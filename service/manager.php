<?php
/**
 *
 * Mail Health. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\mailhealth\service;

/**
 * All the decision making lives here: record a bounce, count the recent ones,
 * apply the configured action, keep the suppression list, follow each
 * affected member through the state machine, raise alerts.
 *
 * Design note - no core event is hooked to block outgoing mail. Instead the
 * native phpBB columns are used (user_notify, user_allow_massemail and, at the
 * top threshold, user_type). phpBB already honours those everywhere it sends,
 * so suppression works for notifications, mass e-mail and any extension that
 * respects the standard flags, with no patching and nothing to break on update.
 *
 * State machine, one row per affected member in the users table:
 *
 *   (no row)  normal
 *      |      threshold reached, action applied
 *      v
 *   BOUNCED   flags switched off; previous values remembered
 *      |      the member (or an admin) changes the address
 *      v
 *   CHANGED   flags restored; the new address is on probation
 *      |      no bounce for the new address during the probation period
 *      v
 *   CONFIRMED the new address works
 *
 * A bounce on the new address during probation sends the member back to
 * BOUNCED. Removing the old address from the suppression list by hand sends
 * him straight back to normal.
 */
class manager
{
	const ACTION_LOG_ONLY		= 0;
	const ACTION_STOP_EMAILS	= 1;
	const ACTION_DEACTIVATE		= 2;

	const STATE_BOUNCED		= 1;
	const STATE_CHANGED		= 2;
	const STATE_CONFIRMED	= 3;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\log\log_interface */
	protected $log;

	/** @var notifier */
	protected $notifier;

	/** @var string */
	protected $bounces_table;

	/** @var string */
	protected $suppress_table;

	/** @var string */
	protected $users_table;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	public function __construct(
		\phpbb\config\config $config,
		\phpbb\db\driver\driver_interface $db,
		\phpbb\user $user,
		\phpbb\log\log_interface $log,
		notifier $notifier,
		$bounces_table,
		$suppress_table,
		$users_table,
		$root_path,
		$php_ext
	)
	{
		$this->config = $config;
		$this->db = $db;
		$this->user = $user;
		$this->log = $log;
		$this->notifier = $notifier;
		$this->bounces_table = $bounces_table;
		$this->suppress_table = $suppress_table;
		$this->users_table = $users_table;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	/* =====================================================================
	 * Bounces
	 * ================================================================== */

	/**
	 * Records one parsed bounce and applies the rules.
	 *
	 * @param array $bounce Output of dsn_parser::parse()
	 * @return bool True when the bounce belonged to a known member
	 */
	public function record(array $bounce)
	{
		$email = strtolower(trim((string) $bounce['email']));
		$user_row = $this->get_user_by_email($email);
		$user_id = $user_row ? (int) $user_row['user_id'] : 0;

		$sql_ary = [
			'user_id'			=> $user_id,
			'bounce_email'		=> $email,
			'bounce_type'		=> (int) $bounce['type'],
			'bounce_status'		=> (string) $bounce['status'],
			'bounce_diagnostic'	=> substr((string) $bounce['diagnostic'], 0, 1000),
			'bounce_time'		=> time(),
		];

		$this->db->sql_query('INSERT INTO ' . $this->bounces_table . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));

		if ($user_id === 0)
		{
			// The address is not (or no longer) a member address. Still worth
			// suppressing so newsletters and mass mail skip it.
			if ((int) $bounce['type'] === dsn_parser::TYPE_HARD)
			{
				$this->suppress($email, 'MAILHEALTH_REASON_HARD', false);
			}

			return false;
		}

		$bounce['email'] = $email;
		$this->evaluate($user_row, $bounce);

		return true;
	}

	/**
	 * Applies the configured thresholds to one member.
	 */
	protected function evaluate(array $user_row, array $bounce)
	{
		$hard_limit = max(1, (int) $this->config['mailhealth_hard_limit']);
		$soft_limit = max(1, (int) $this->config['mailhealth_soft_limit']);

		$counts = $this->count_recent($user_row['user_email'], $this->get_period_start());

		if ($counts['hard'] >= $hard_limit)
		{
			$reason = 'MAILHEALTH_REASON_HARD';
		}
		else if ($counts['soft'] >= $soft_limit)
		{
			$reason = 'MAILHEALTH_REASON_SOFT';
		}
		else
		{
			return;
		}

		if ($this->config['mailhealth_notify_admin'])
		{
			$this->log->add('admin', ANONYMOUS, '', 'LOG_MAILHEALTH_TRIGGERED', time(), [
				$user_row['username'],
				$bounce['email'],
				$bounce['status'],
			]);
		}

		$action = (int) $this->config['mailhealth_action'];

		if ($action === self::ACTION_LOG_ONLY)
		{
			return;
		}

		$this->suppress($user_row['user_email'], $reason, false);

		if ($this->enter_bounced_state($user_row))
		{
			// Only on the way in, never again for the same address
			$this->notifier->notify_bounced($user_row);
		}

		$sql_ary = [
			'user_notify'			=> 0,
			'user_allow_massemail'	=> 0,
		];

		$deactivate = ($action === self::ACTION_DEACTIVATE && (int) $user_row['user_type'] !== USER_FOUNDER);

		if ($deactivate && (int) $user_row['user_type'] !== USER_INACTIVE)
		{
			$sql_ary['user_type'] = USER_INACTIVE;
			$sql_ary['user_inactive_reason'] = INACTIVE_PROFILE;
			$sql_ary['user_inactive_time'] = time();
		}

		$this->db->sql_query('UPDATE ' . USERS_TABLE . '
			SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
			WHERE user_id = ' . (int) $user_row['user_id']);

		if (isset($sql_ary['user_type']))
		{
			$this->config->increment('num_users', -1, false);
		}
	}

	/**
	 * Counts bounces for one address inside the retention window.
	 *
	 * @return array ['hard' => int, 'soft' => int]
	 */
	public function count_recent($email, $since)
	{
		$counts = ['hard' => 0, 'soft' => 0];

		$sql = 'SELECT bounce_type, COUNT(bounce_id) AS total
			FROM ' . $this->bounces_table . "
			WHERE bounce_email = '" . $this->db->sql_escape(strtolower($email)) . "'
				AND bounce_time >= " . (int) $since . '
			GROUP BY bounce_type';
		$result = $this->db->sql_query($sql);

		while ($row = $this->db->sql_fetchrow($result))
		{
			if ((int) $row['bounce_type'] === dsn_parser::TYPE_HARD)
			{
				$counts['hard'] = (int) $row['total'];
			}
			else if ((int) $row['bounce_type'] === dsn_parser::TYPE_SOFT)
			{
				$counts['soft'] = (int) $row['total'];
			}
		}
		$this->db->sql_freeresult($result);

		return $counts;
	}

	public function get_period_start()
	{
		$days = max(1, (int) $this->config['mailhealth_record_period']);

		return time() - ($days * 86400);
	}

	/**
	 * Deletes bounce records by id. Used by the bulk actions of the log.
	 */
	public function delete_bounces(array $ids)
	{
		$ids = array_values(array_filter(array_map('intval', $ids)));

		if (empty($ids))
		{
			return;
		}

		$this->db->sql_query('DELETE FROM ' . $this->bounces_table . ' WHERE ' . $this->db->sql_in_set('bounce_id', $ids));
	}

	/**
	 * Addresses of the given bounce records, lower case, unique.
	 *
	 * @return array
	 */
	public function emails_of_bounces(array $ids)
	{
		$ids = array_values(array_filter(array_map('intval', $ids)));
		$emails = [];

		if (empty($ids))
		{
			return $emails;
		}

		$result = $this->db->sql_query('SELECT DISTINCT bounce_email FROM ' . $this->bounces_table . ' WHERE ' . $this->db->sql_in_set('bounce_id', $ids));
		while ($row = $this->db->sql_fetchrow($result))
		{
			$emails[] = strtolower($row['bounce_email']);
		}
		$this->db->sql_freeresult($result);

		return $emails;
	}

	/**
	 * Wipes every recorded bounce of the given addresses, so their counters
	 * start again from zero. Suppression is left as it is.
	 */
	public function reset_counters(array $emails)
	{
		$emails = array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $emails)))));

		if (empty($emails))
		{
			return;
		}

		$this->db->sql_query('DELETE FROM ' . $this->bounces_table . ' WHERE ' . $this->db->sql_in_set('bounce_email', $emails));
	}

	/* =====================================================================
	 * Suppression list
	 * ================================================================== */

	/**
	 * Adds an address to the suppression list. Idempotent.
	 */
	public function suppress($email, $reason, $manual = false)
	{
		$email = strtolower(trim($email));

		if ($email === '' || $this->is_suppressed($email))
		{
			return;
		}

		$sql_ary = [
			'suppress_email'	=> $email,
			'suppress_reason'	=> (string) $reason,
			'suppress_manual'	=> (bool) $manual,
			'suppress_time'		=> time(),
		];

		$this->db->sql_query('INSERT INTO ' . $this->suppress_table . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
	}

	/**
	 * Removes an address from the suppression list. A member still waiting
	 * in BOUNCED state for that very address gets his settings back: the
	 * administrator has just said the address is fine.
	 */
	public function unsuppress($email)
	{
		$email = strtolower(trim($email));

		$this->db->sql_query('DELETE FROM ' . $this->suppress_table . "
			WHERE suppress_email = '" . $this->db->sql_escape($email) . "'");

		$sql = 'SELECT *
			FROM ' . $this->users_table . '
			WHERE mh_state = ' . self::STATE_BOUNCED . "
				AND mh_bounced_email = '" . $this->db->sql_escape($email) . "'";
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		foreach ($rows as $row)
		{
			$this->restore_user($row);
			$this->delete_state((int) $row['user_id']);
		}
	}

	public function is_suppressed($email)
	{
		$sql = 'SELECT suppress_id
			FROM ' . $this->suppress_table . "
			WHERE suppress_email = '" . $this->db->sql_escape(strtolower(trim($email))) . "'";
		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return (bool) $row;
	}

	/**
	 * Every suppressed address. Used by other extensions (the Newsletter)
	 * to filter their recipients.
	 *
	 * @return array
	 */
	public function get_suppression_list()
	{
		$emails = [];

		$result = $this->db->sql_query('SELECT suppress_email FROM ' . $this->suppress_table);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$emails[] = $row['suppress_email'];
		}
		$this->db->sql_freeresult($result);

		return $emails;
	}

	/* =====================================================================
	 * State machine
	 * ================================================================== */

	/**
	 * Remembers the member's settings before switching them off. If a row
	 * already exists for an earlier bounce, the remembered values stay the
	 * original ones - never the already switched off ones.
	 */
	protected function enter_bounced_state(array $user_row)
	{
		$user_id = (int) $user_row['user_id'];
		$existing = $this->get_state($user_id);

		if ($existing && (int) $existing['mh_state'] === self::STATE_BOUNCED)
		{
			return false;
		}

		$sql_ary = [
			'mh_state'			=> self::STATE_BOUNCED,
			'mh_bounced_email'	=> strtolower($user_row['user_email']),
			'mh_new_email'		=> '',
			'mh_state_time'		=> time(),
		];

		if (!$existing)
		{
			$sql_ary += [
				'user_id'			=> $user_id,
				'mh_prev_notify'	=> (int) $user_row['user_notify'],
				'mh_prev_massemail'	=> (int) $user_row['user_allow_massemail'],
				'mh_prev_type'		=> (int) $user_row['user_type'],
			];

			$this->db->sql_query('INSERT INTO ' . $this->users_table . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
			return true;
		}

		// Back from CHANGED or CONFIRMED: the flags currently set are the
		// ones restored earlier, so they are the right ones to remember.
		$sql_ary['mh_prev_notify'] = (int) $user_row['user_notify'];
		$sql_ary['mh_prev_massemail'] = (int) $user_row['user_allow_massemail'];
		$sql_ary['mh_prev_type'] = (int) $user_row['user_type'];

		$this->db->sql_query('UPDATE ' . $this->users_table . '
			SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
			WHERE user_id = ' . $user_id);

		return true;
	}

	/**
	 * Moves members forward through the states. Run by the cron task.
	 *
	 * @return array ['changed' => int, 'confirmed' => int, 'orphans' => int]
	 */
	public function sync_states()
	{
		$stats = ['changed' => 0, 'confirmed' => 0, 'orphans' => 0];

		// BOUNCED -> CHANGED: the address on the account is no longer the
		// one that bounced, and is not itself on the suppression list.
		$sql = 'SELECT s.*, u.username, u.user_email, u.user_lang, u.user_type, u.user_inactive_reason, u.user_inactive_time
			FROM ' . $this->users_table . ' s
			LEFT JOIN ' . USERS_TABLE . ' u ON (u.user_id = s.user_id)
			WHERE s.mh_state = ' . self::STATE_BOUNCED;
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		foreach ($rows as $row)
		{
			if ($row['user_email'] === null)
			{
				$this->delete_state((int) $row['user_id']);
				$stats['orphans']++;
				continue;
			}

			$current = strtolower(trim($row['user_email']));

			if ($current === '' || $current === $row['mh_bounced_email'] || $this->is_suppressed($current))
			{
				continue;
			}

			$this->restore_user($row);

			// Verification message to the new address; the token proves a
			// click on the link came from whoever can read that mailbox.
			$token = $this->notifier->notify_changed($row, $current);

			$this->db->sql_query('UPDATE ' . $this->users_table . '
				SET ' . $this->db->sql_build_array('UPDATE', [
					'mh_state'			=> self::STATE_CHANGED,
					'mh_new_email'		=> $current,
					'mh_token'			=> $token,
					'mh_token_time'		=> time(),
					'mh_state_time'		=> time(),
				]) . '
				WHERE user_id = ' . (int) $row['user_id']);

			$stats['changed']++;
		}

		// CHANGED -> CONFIRMED: probation over, no bounce for the new address.
		$probation = max(1, (int) $this->config['mailhealth_confirm_days']) * 86400;

		$sql = 'SELECT *
			FROM ' . $this->users_table . '
			WHERE mh_state = ' . self::STATE_CHANGED . '
				AND mh_state_time < ' . (time() - $probation);
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		foreach ($rows as $row)
		{
			$counts = $this->count_recent($row['mh_new_email'], (int) $row['mh_state_time']);

			if ($counts['hard'] > 0 || $counts['soft'] > 0)
			{
				// Still bouncing but under the limits: keep watching.
				continue;
			}

			$this->db->sql_query('UPDATE ' . $this->users_table . '
				SET mh_state = ' . self::STATE_CONFIRMED . ', mh_state_time = ' . time() . '
				WHERE user_id = ' . (int) $row['user_id']);

			$stats['confirmed']++;
		}

		// Confirmed rows are history: keep them as long as bounces are kept.
		$this->db->sql_query('DELETE FROM ' . $this->users_table . '
			WHERE mh_state = ' . self::STATE_CONFIRMED . '
				AND mh_state_time < ' . (int) $this->get_period_start());

		return $stats;
	}

	/**
	 * Gives a member back the settings remembered when he entered BOUNCED.
	 * An account deactivated by this extension is reactivated only when it
	 * is still inactive for that same reason - never one an administrator
	 * has deactivated since for something else.
	 */
	public function restore_user(array $state_row)
	{
		$user_id = (int) $state_row['user_id'];

		$sql = 'SELECT user_type, user_inactive_reason, user_inactive_time
			FROM ' . USERS_TABLE . '
			WHERE user_id = ' . $user_id;
		$result = $this->db->sql_query($sql);
		$user = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$user)
		{
			return;
		}

		$sql_ary = [
			'user_notify'			=> (int) $state_row['mh_prev_notify'],
			'user_allow_massemail'	=> (int) $state_row['mh_prev_massemail'],
		];

		$was_active = in_array((int) $state_row['mh_prev_type'], [USER_NORMAL, USER_FOUNDER], true);

		if ($was_active
			&& (int) $user['user_type'] === USER_INACTIVE
			&& (int) $user['user_inactive_reason'] === INACTIVE_PROFILE
			&& (int) $user['user_inactive_time'] >= (int) $state_row['mh_state_time'] - 60)
		{
			$sql_ary['user_type'] = (int) $state_row['mh_prev_type'];
			$sql_ary['user_inactive_reason'] = 0;
			$sql_ary['user_inactive_time'] = 0;
		}

		$this->db->sql_query('UPDATE ' . USERS_TABLE . '
			SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
			WHERE user_id = ' . $user_id);

		if (isset($sql_ary['user_type']))
		{
			$this->config->increment('num_users', 1, false);
		}
	}

	/**
	 * @return array|false
	 */
	public function get_state($user_id)
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->users_table . ' WHERE user_id = ' . (int) $user_id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ? $row : false;
	}

	public function delete_state($user_id)
	{
		$this->db->sql_query('DELETE FROM ' . $this->users_table . ' WHERE user_id = ' . (int) $user_id);
	}

	/**
	 * Manual reset from the ACP: settings back, row gone, the bounced
	 * address taken off the suppression list and its counters cleared.
	 */
	public function reset_user($user_id)
	{
		$row = $this->get_state($user_id);

		if (!$row)
		{
			return;
		}

		if ((int) $row['mh_state'] === self::STATE_BOUNCED)
		{
			$this->restore_user($row);
		}

		$this->db->sql_query('DELETE FROM ' . $this->suppress_table . "
			WHERE suppress_email = '" . $this->db->sql_escape($row['mh_bounced_email']) . "'");

		// A fresh start: an old bounce left in the log would count towards
		// the limits again and switch the member off at the next one.
		$this->reset_counters([$row['mh_bounced_email']]);

		$this->delete_state($user_id);
	}

	/**
	 * The member clicked the link in the verification e-mail: that address
	 * demonstrably receives mail, so the probation ends right now.
	 *
	 * @return bool
	 */
	public function confirm_token($user_id, $token)
	{
		$token = strtolower(trim((string) $token));

		if (!preg_match('/^[a-f0-9]{32}$/', $token))
		{
			return false;
		}

		$row = $this->get_state((int) $user_id);

		if (!$row || (string) $row['mh_token'] === '' || !hash_equals((string) $row['mh_token'], $token))
		{
			return false;
		}

		// Already confirmed with this very token: say yes again, so a second
		// click on the same link is not reported as a failure.
		if ((int) $row['mh_state'] === self::STATE_CONFIRMED)
		{
			return true;
		}

		if ((int) $row['mh_state'] !== self::STATE_CHANGED)
		{
			return false;
		}

		$this->db->sql_query('UPDATE ' . $this->users_table . '
			SET mh_state = ' . self::STATE_CONFIRMED . ', mh_state_time = ' . time() . '
			WHERE user_id = ' . (int) $user_id);

		return true;
	}

	/* =====================================================================
	 * Alerts
	 * ================================================================== */

	/**
	 * Warns the administrator when bounces pile up.
	 *
	 * phpBB does not count the e-mails it sends, so a true bounce *rate* is
	 * not available; what is measured is the number of bounces received in
	 * the last 24 hours. At most one alert per day, however bad things get.
	 *
	 * @return bool True when an alert was raised
	 */
	public function check_alert()
	{
		$limit = (int) $this->config['mailhealth_alert_limit'];

		if ($limit <= 0 || (int) $this->config['mailhealth_alert_last'] > time() - 86400)
		{
			return false;
		}

		$since = time() - 86400;

		$sql = 'SELECT COUNT(bounce_id) AS total,
				SUM(CASE WHEN bounce_type = ' . dsn_parser::TYPE_HARD . ' THEN 1 ELSE 0 END) AS hard
			FROM ' . $this->bounces_table . '
			WHERE bounce_time >= ' . $since;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		$total = (int) $row['total'];

		if ($total < $limit)
		{
			return false;
		}

		$hard = (int) $row['hard'];

		$this->config->set('mailhealth_alert_last', time(), false);

		$this->log->add('critical', ANONYMOUS, '', 'LOG_MAILHEALTH_ALERT', time(), [$total, $hard, $limit]);

		if ($this->config['mailhealth_alert_email'] && !empty($this->config['email_enable']))
		{
			$this->send_alert_email($total, $hard, $limit);
		}

		return true;
	}

	protected function send_alert_email($total, $hard, $limit)
	{
		$to = (string) $this->config['board_contact'];

		if ($to === '')
		{
			return;
		}

		if (!class_exists('messenger'))
		{
			include($this->root_path . 'includes/functions_messenger.' . $this->php_ext);
		}

		$messenger = new \messenger(false);
		$messenger->template('@salvocortesiano_mailhealth/mailhealth_alert', (string) $this->config['default_lang']);
		$messenger->to($to, (string) $this->config['sitename']);
		$messenger->assign_vars([
			'BOUNCES_TOTAL'	=> $total,
			'BOUNCES_HARD'	=> $hard,
			'BOUNCES_SOFT'	=> $total - $hard,
			'ALERT_LIMIT'	=> $limit,
			'U_ACP'			=> generate_board_url() . '/adm/index.' . $this->php_ext . '?i=-salvocortesiano-mailhealth-acp-main_module&mode=bounces',
		]);
		$messenger->send(NOTIFY_EMAIL);
		$messenger->save_queue();
	}

	/* =====================================================================
	 * Statistics
	 * ================================================================== */

	/**
	 * Which mail providers the bounces come from, worst first.
	 *
	 * One address failing is the member's problem; thirty addresses at the
	 * same provider failing is the board's problem - a blacklisted IP or a
	 * provider that has started refusing the mail.
	 *
	 * @param int $limit How many domains to return
	 * @return array [['domain' => string, 'hard' => int, 'soft' => int, 'total' => int, 'addresses' => int], ...]
	 */
	public function domain_stats($limit = 10)
	{
		$domains = [];
		$seen = [];

		$sql = 'SELECT bounce_email, bounce_type
			FROM ' . $this->bounces_table . '
			WHERE bounce_time >= ' . (int) $this->get_period_start();
		$result = $this->db->sql_query_limit($sql, 20000);

		while ($row = $this->db->sql_fetchrow($result))
		{
			$at = strrpos($row['bounce_email'], '@');

			if ($at === false)
			{
				continue;
			}

			$domain = substr($row['bounce_email'], $at + 1);

			if (!isset($domains[$domain]))
			{
				$domains[$domain] = ['domain' => $domain, 'hard' => 0, 'soft' => 0, 'total' => 0, 'addresses' => 0];
				$seen[$domain] = [];
			}

			$domains[$domain][((int) $row['bounce_type'] === dsn_parser::TYPE_HARD) ? 'hard' : 'soft']++;
			$domains[$domain]['total']++;

			if (!isset($seen[$domain][$row['bounce_email']]))
			{
				$seen[$domain][$row['bounce_email']] = true;
				$domains[$domain]['addresses']++;
			}
		}
		$this->db->sql_freeresult($result);

		uasort($domains, function ($a, $b) {
			return $b['total'] - $a['total'];
		});

		return array_slice(array_values($domains), 0, max(1, (int) $limit));
	}

	/**
	 * Bounces per week for the chart, oldest first.
	 *
	 * @param int $weeks
	 * @return array [['start' => timestamp, 'hard' => int, 'soft' => int], ...]
	 */
	public function weekly_stats($weeks = 12)
	{
		$weeks = max(1, (int) $weeks);
		$now = time();
		$buckets = [];

		for ($i = $weeks - 1; $i >= 0; $i--)
		{
			$buckets[$i] = ['start' => $now - (($i + 1) * 604800), 'hard' => 0, 'soft' => 0];
		}

		$sql = 'SELECT bounce_time, bounce_type
			FROM ' . $this->bounces_table . '
			WHERE bounce_time >= ' . ($now - $weeks * 604800);
		$result = $this->db->sql_query($sql);

		while ($row = $this->db->sql_fetchrow($result))
		{
			$index = (int) floor(($now - (int) $row['bounce_time']) / 604800);

			if (!isset($buckets[$index]))
			{
				continue;
			}

			if ((int) $row['bounce_type'] === dsn_parser::TYPE_HARD)
			{
				$buckets[$index]['hard']++;
			}
			else
			{
				$buckets[$index]['soft']++;
			}
		}
		$this->db->sql_freeresult($result);

		return array_values($buckets);
	}

	/* =====================================================================
	 * Board side
	 * ================================================================== */

	/**
	 * Has the currently logged in member got a delivery problem?
	 * Used by the listener to show the board notice.
	 */
	public function current_user_has_problem()
	{
		if (empty($this->user->data['user_email']) || $this->user->data['user_id'] == ANONYMOUS)
		{
			return false;
		}

		return $this->is_suppressed($this->user->data['user_email']);
	}

	/**
	 * Housekeeping: drops records older than the retention window.
	 */
	public function prune()
	{
		$this->db->sql_query('DELETE FROM ' . $this->bounces_table . '
			WHERE bounce_time < ' . (int) $this->get_period_start());
	}

	protected function get_user_by_email($email)
	{
		$sql = 'SELECT user_id, username, user_email, user_type, user_notify, user_allow_massemail, user_lang
			FROM ' . USERS_TABLE . "
			WHERE LOWER(user_email) = '" . $this->db->sql_escape(strtolower($email)) . "'
				AND user_type <> " . USER_IGNORE;
		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ? $row : false;
	}
}
