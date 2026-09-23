<?php
/**
 *
 * Mail Health. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\mailhealth\acp;

use salvocortesiano\mailhealth\service\diagnostics;
use salvocortesiano\mailhealth\service\dsn_parser;
use salvocortesiano\mailhealth\service\integration;
use salvocortesiano\mailhealth\service\manager;

class main_module
{
	const PER_PAGE = 50;
	const FORM_KEY = 'salvocortesiano_mailhealth';

	/** @var string */
	public $u_action;

	/** @var string */
	public $page_title;

	/** @var string */
	public $tpl_name;

	/** @var \Symfony\Component\DependencyInjection\ContainerInterface */
	protected $container;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\user */
	protected $user;

	/** @var manager */
	protected $manager;

	public function main($id, $mode)
	{
		global $config, $db, $request, $template, $user, $phpbb_container;

		$this->container = $phpbb_container;
		$this->config = $config;
		$this->db = $db;
		$this->language = $phpbb_container->get('language');
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
		$this->manager = $phpbb_container->get('salvocortesiano.mailhealth.manager');

		$this->language->add_lang(['common', 'mailhealth_acp'], 'salvocortesiano/mailhealth');

		add_form_key(self::FORM_KEY);

		switch ($mode)
		{
			case 'settings':
				$this->mode_settings();
			break;

			case 'bounces':
				$this->mode_bounces();
			break;

			case 'users':
				$this->mode_users();
			break;

			case 'check':
				$this->mode_check();
			break;

			case 'suppress':
				$this->mode_suppress();
			break;
		}

		$this->template->assign_var('U_ACTION', $this->u_action);
		$this->assign_badges();
	}

	/**
	 * Version, phpBB, PHP and licence shown under the title of every tab.
	 * Version and licence come from composer.json, so they always match the
	 * files actually installed; phpBB and PHP turn red when outside the
	 * supported range.
	 */
	protected function assign_badges()
	{
		$version = '?';
		$license = '?';

		try
		{
			$metadata = $this->container->get('ext.manager')
				->create_extension_metadata_manager('salvocortesiano/mailhealth')
				->get_metadata('all');

			$version = isset($metadata['version']) ? (string) $metadata['version'] : '?';
			$license = isset($metadata['license']) ? (string) $metadata['license'] : '?';
		}
		catch (\Exception $e)
		{
			// A broken composer.json must not take the ACP page down with it.
		}

		$phpbb = (string) $this->config['version'];

		$this->template->assign_vars([
			'MAILHEALTH_BADGE_VERSION'	=> $version,
			'MAILHEALTH_BADGE_PHPBB'	=> $phpbb,
			'MAILHEALTH_BADGE_PHP'		=> PHP_VERSION,
			'MAILHEALTH_BADGE_LICENSE'	=> $license,
			'S_MAILHEALTH_PHPBB_OK'		=> phpbb_version_compare($phpbb, '3.3.0', '>=') && phpbb_version_compare($phpbb, '4.0.0-dev', '<'),
			'S_MAILHEALTH_PHP_OK'		=> version_compare(PHP_VERSION, '7.2.0', '>='),
		]);
	}

	/* =====================================================================
	 * Settings
	 * ================================================================== */

	protected function mode_settings()
	{
		$this->tpl_name = 'acp_mailhealth_settings';
		$this->page_title = $this->language->lang('ACP_MAILHEALTH_SETTINGS');

		/** @var \salvocortesiano\mailhealth\service\imap_client $imap */
		$imap = $this->container->get('salvocortesiano.mailhealth.imap_client');
		/** @var \salvocortesiano\mailhealth\service\crypto $crypto */
		$crypto = $this->container->get('salvocortesiano.mailhealth.crypto');
		/** @var integration $integration */
		$integration = $this->container->get('salvocortesiano.mailhealth.integration');

		if ($this->request->is_set_post('submit'))
		{
			$this->check_form();

			$settings = [
				'mailhealth_enabled'		=> 'bool',
				'mailhealth_imap_transport'	=> 'int',
				'mailhealth_imap_host'		=> 'string',
				'mailhealth_imap_port'		=> 'int',
				'mailhealth_imap_security'	=> 'int',
				'mailhealth_imap_folder'	=> 'string',
				'mailhealth_imap_user'		=> 'string',
				'mailhealth_imap_delete'	=> 'bool',
				'mailhealth_batch_size'		=> 'int',
				'mailhealth_hard_limit'		=> 'int',
				'mailhealth_soft_limit'		=> 'int',
				'mailhealth_record_period'	=> 'int',
				'mailhealth_confirm_days'	=> 'int',
				'mailhealth_action'			=> 'int',
				'mailhealth_notify_admin'	=> 'bool',
				'mailhealth_pm_enabled'		=> 'bool',
				'mailhealth_verify_enabled'	=> 'bool',
				'mailhealth_alert_limit'	=> 'int',
				'mailhealth_alert_email'	=> 'bool',
				'mailhealth_cron_interval'	=> 'int',
				'mailhealth_dkim_selector'	=> 'string',
			];

			// Lower bounds, so a typo cannot switch a feature into nonsense
			$minimum = [
				'mailhealth_imap_port'		=> 1,
				'mailhealth_batch_size'		=> 1,
				'mailhealth_hard_limit'		=> 1,
				'mailhealth_soft_limit'		=> 1,
				'mailhealth_record_period'	=> 1,
				'mailhealth_confirm_days'	=> 1,
				'mailhealth_cron_interval'	=> 60,
			];

			// Remember which mailbox was configured, to know whether it changes
			$old_mailbox = strtolower($this->config['mailhealth_imap_host'] . '|' . $this->config['mailhealth_imap_user'] . '|' . $this->config['mailhealth_imap_folder']);

			foreach ($settings as $key => $type)
			{
				if ($type === 'string')
				{
					$value = trim($this->request->variable($key, '', true));
				}
				else
				{
					$value = (int) $this->request->variable($key, 0);

					if (isset($minimum[$key]))
					{
						$value = max($minimum[$key], $value);
					}
				}

				$this->config->set($key, $value);
			}

			// A different mailbox has its own UIDs: start reading it from the top
			if (strtolower($this->config['mailhealth_imap_host'] . '|' . $this->config['mailhealth_imap_user'] . '|' . $this->config['mailhealth_imap_folder']) !== $old_mailbox)
			{
				$imap->reset_position();
			}

			$this->config->set('mailhealth_dkim_selector', \salvocortesiano\mailhealth\service\dns_check::normalize_selector((string) $this->config['mailhealth_dkim_selector']));

			// The password is only overwritten when something was typed,
			// so the form can be saved without retyping it every time.
			// It never reaches the database in the clear.
			$password = $this->request->variable('mailhealth_imap_pass', '', true);

			if ($this->request->variable('mailhealth_clear_pass', false))
			{
				$this->config->set('mailhealth_imap_pass', '');
			}
			else if ($password !== '')
			{
				$encrypted = $crypto->encrypt($password);

				if (function_exists('sodium_memzero'))
				{
					sodium_memzero($password);
				}

				if ($encrypted === false)
				{
					trigger_error($this->language->lang($crypto->get_error()) . adm_back_link($this->u_action), E_USER_WARNING);
				}

				$this->config->set('mailhealth_imap_pass', $encrypted);
			}

			// Sender of the private messages, given by username
			$sender_name = trim($this->request->variable('mailhealth_pm_sender_name', '', true));

			if ($sender_name === '')
			{
				$this->config->set('mailhealth_pm_sender', 0);
			}
			else
			{
				$sql = 'SELECT user_id FROM ' . USERS_TABLE . "
					WHERE username_clean = '" . $this->db->sql_escape(utf8_clean_string($sender_name)) . "'";
				$result = $this->db->sql_query($sql);
				$sender_id = (int) $this->db->sql_fetchfield('user_id');
				$this->db->sql_freeresult($result);

				if ($sender_id === 0)
				{
					trigger_error($this->language->lang('MAILHEALTH_PM_SENDER_UNKNOWN', $sender_name) . adm_back_link($this->u_action), E_USER_WARNING);
				}

				$this->config->set('mailhealth_pm_sender', $sender_id);
			}

			$this->container->get('log')->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_MAILHEALTH_SETTINGS');

			trigger_error($this->language->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
		}

		if ($this->request->is_set_post('test_connection'))
		{
			$this->check_form();

			if ($imap->connect())
			{
				$imap->close(false);
				$via = $this->language->lang($imap->get_transport_name() === 'socket' ? 'MAILHEALTH_TRANSPORT_NATIVE' : 'MAILHEALTH_TRANSPORT_EXT');
				trigger_error($this->language->lang('MAILHEALTH_TEST_OK_VIA', $via) . adm_back_link($this->u_action));
			}

			trigger_error($this->language->lang('MAILHEALTH_TEST_FAILED', $imap->get_error_message($this->language)) . adm_back_link($this->u_action), E_USER_WARNING);
		}

		// Tries the usual port and security combinations against the server
		// and keeps the first one that answers. No login is attempted.
		if ($this->request->is_set_post('detect_settings'))
		{
			$this->check_form();

			$host = trim($this->request->variable('mailhealth_imap_host', '', true));
			$host = ($host !== '') ? $host : trim((string) $this->config['mailhealth_imap_host']);

			if ($host === '')
			{
				trigger_error($this->language->lang('MAILHEALTH_ERROR_NOT_CONFIGURED') . adm_back_link($this->u_action), E_USER_WARNING);
			}

			$results = $imap->detect_settings($host);
			$lines = [];
			$found = null;
			$unreachable = 0;

			foreach ($results as $result)
			{
				$label = $this->language->lang('MAILHEALTH_DETECT_ATTEMPT', $result['port'], $this->language->lang('MAILHEALTH_SECURITY_' . $result['security']));

				if ($result['ok'])
				{
					$found = $result;
					$lines[] = '&#10004; ' . $label . $this->language->lang('MAILHEALTH_DETECT_WORKS');
				}
				else
				{
					if ($result['error'][0] === 'MAILHEALTH_IMAP_CONNECT_FAILED')
					{
						$unreachable++;
					}

					$lines[] = '&#10006; ' . $label . \salvocortesiano\mailhealth\service\imap_client::format_error($result['error'], $this->language);
				}
			}

			$list = '<br /><br />' . implode('<br />', $lines);

			if ($found !== null)
			{
				$this->config->set('mailhealth_imap_host', $host);
				$this->config->set('mailhealth_imap_port', $found['port']);
				$this->config->set('mailhealth_imap_security', $found['security']);

				trigger_error($this->language->lang('MAILHEALTH_DETECT_FOUND', $found['port'], $this->language->lang('MAILHEALTH_SECURITY_' . $found['security'])) . $list . adm_back_link($this->u_action));
			}

			$advice = ($unreachable === count($results)) ? '<br /><br />' . $this->language->lang('MAILHEALTH_DETECT_BLOCKED', $host) : '';
			trigger_error($this->language->lang('MAILHEALTH_DETECT_NONE', $host) . $list . $advice . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$stored_pass = (string) $this->config['mailhealth_imap_pass'];
		$newsletter = $integration->get_state();

		$this->template->assign_vars([
			'S_IMAP_AVAILABLE'			=> $imap->is_available(),
			'S_IMAP_EXT'				=> $imap::ext_available(),
			'MAILHEALTH_TRANSPORT_NAME'	=> $imap->get_transport_name(),
			'S_CRYPTO_AVAILABLE'		=> $crypto->is_available(),
			'S_PASS_STORED'				=> $stored_pass !== '',
			'S_PASS_PLAINTEXT'			=> $stored_pass !== '' && !$crypto->is_encrypted($stored_pass),
			'S_KEY_IN_CONFIG'			=> $crypto->key_in_config(),
			'S_KEY_EXISTS'				=> $crypto->key_exists(),
			'MAILHEALTH_CIPHER'			=> $crypto->get_cipher(),
			'MAILHEALTH_KEY_PATH'		=> $crypto->get_key_display_path(),

			'S_NEWSLETTER_ENABLED'		=> $newsletter === integration::STATE_ENABLED,
			'S_NEWSLETTER_DISABLED'		=> $newsletter === integration::STATE_DISABLED,
			'S_NEWSLETTER_MISSING'		=> $newsletter === integration::STATE_MISSING,
			'MAILHEALTH_BLOCKED_COUNT'	=> $integration->count_blocked(),

			'MAILHEALTH_ENABLED'		=> (bool) $this->config['mailhealth_enabled'],
			'MAILHEALTH_IMAP_TRANSPORT'	=> (int) $this->config['mailhealth_imap_transport'],
			'MAILHEALTH_IMAP_HOST'		=> $this->config['mailhealth_imap_host'],
			'MAILHEALTH_IMAP_PORT'		=> (int) $this->config['mailhealth_imap_port'],
			'MAILHEALTH_IMAP_SECURITY'	=> (int) $this->config['mailhealth_imap_security'],
			'MAILHEALTH_IMAP_FOLDER'	=> $this->config['mailhealth_imap_folder'],
			'MAILHEALTH_IMAP_USER'		=> $this->config['mailhealth_imap_user'],
			'MAILHEALTH_IMAP_DELETE'	=> (bool) $this->config['mailhealth_imap_delete'],
			'MAILHEALTH_BATCH_SIZE'		=> (int) $this->config['mailhealth_batch_size'],
			'MAILHEALTH_HARD_LIMIT'		=> (int) $this->config['mailhealth_hard_limit'],
			'MAILHEALTH_SOFT_LIMIT'		=> (int) $this->config['mailhealth_soft_limit'],
			'MAILHEALTH_RECORD_PERIOD'	=> (int) $this->config['mailhealth_record_period'],
			'MAILHEALTH_CONFIRM_DAYS'	=> (int) $this->config['mailhealth_confirm_days'],
			'MAILHEALTH_ACTION'			=> (int) $this->config['mailhealth_action'],
			'MAILHEALTH_NOTIFY_ADMIN'	=> (bool) $this->config['mailhealth_notify_admin'],
			'MAILHEALTH_PM_ENABLED'		=> (bool) $this->config['mailhealth_pm_enabled'],
			'MAILHEALTH_VERIFY_ENABLED'	=> (bool) $this->config['mailhealth_verify_enabled'],
			'MAILHEALTH_PM_SENDER_NAME'	=> $this->pm_sender_name(),
			'MAILHEALTH_ALERT_LIMIT'	=> (int) $this->config['mailhealth_alert_limit'],
			'MAILHEALTH_ALERT_EMAIL'	=> (bool) $this->config['mailhealth_alert_email'],
			'MAILHEALTH_BOARD_CONTACT'	=> $this->config['board_contact'],
			'MAILHEALTH_CRON_INTERVAL'	=> (int) $this->config['mailhealth_cron_interval'],
			'MAILHEALTH_DKIM_SELECTOR'	=> $this->config['mailhealth_dkim_selector'],
			'MAILHEALTH_LAST_RUN'		=> $this->config['mailhealth_last_run'] ? $this->user->format_date((int) $this->config['mailhealth_last_run']) : $this->language->lang('MAILHEALTH_NEVER'),
		]);
	}

	protected function pm_sender_name()
	{
		$sender_id = (int) $this->config['mailhealth_pm_sender'];

		if ($sender_id === 0)
		{
			return '';
		}

		$result = $this->db->sql_query('SELECT username FROM ' . USERS_TABLE . ' WHERE user_id = ' . $sender_id);
		$name = (string) $this->db->sql_fetchfield('username');
		$this->db->sql_freeresult($result);

		return $name;
	}

	/* =====================================================================
	 * Bounce log
	 * ================================================================== */

	protected function mode_bounces()
	{
		$this->tpl_name = 'acp_mailhealth_list';
		$this->page_title = $this->language->lang('ACP_MAILHEALTH_BOUNCES');

		$table = $this->container->getParameter('salvocortesiano.mailhealth.table.bounces');

		$search = trim($this->request->variable('q', '', true));
		$type = $this->request->variable('type', 0);
		$start = $this->request->variable('start', 0);

		$where = $this->bounce_where($search, $type);

		if ($this->request->variable('export', '') === 'csv')
		{
			$this->check_export_hash();
			$this->export_bounces($table, $where);
		}

		// Bulk actions
		$action = $this->request->variable('bulk_action', '');
		$ids = array_filter(array_map('intval', $this->request->variable('mark', [0])));

		// Button pressed with something missing: say what, instead of
		// silently reloading the page.
		if ($this->request->is_set_post('bulk') && !$this->request->is_set_post('cancel'))
		{
			if (empty($ids))
			{
				trigger_error($this->language->lang('MAILHEALTH_BULK_NONE') . adm_back_link($this->list_url($search, $type)), E_USER_WARNING);
			}

			if (!in_array($action, ['suppress', 'delete', 'reset'], true))
			{
				trigger_error($this->language->lang('MAILHEALTH_BULK_NO_ACTION') . adm_back_link($this->list_url($search, $type)), E_USER_WARNING);
			}
		}

		if (in_array($action, ['suppress', 'delete', 'reset'], true) && !empty($ids) && !$this->request->is_set_post('cancel'))
		{
			if (confirm_box(true))
			{
				$emails = $this->manager->emails_of_bounces($ids);

				if ($action === 'suppress')
				{
					foreach ($emails as $email)
					{
						$this->manager->suppress($email, 'MAILHEALTH_REASON_MANUAL', true);
					}
				}
				else if ($action === 'delete')
				{
					$this->manager->delete_bounces($ids);
				}
				else
				{
					$this->manager->reset_counters($emails);
				}

				$this->container->get('log')->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_MAILHEALTH_BULK', false, [
					$this->language->lang('MAILHEALTH_BULK_' . strtoupper($action)),
					count($ids),
				]);

				trigger_error($this->language->lang('MAILHEALTH_BULK_DONE', count($ids)) . adm_back_link($this->list_url($search, $type)));
			}
			else
			{
				confirm_box(false, $this->language->lang('MAILHEALTH_BULK_' . strtoupper($action) . '_CONFIRM'), build_hidden_fields([
					'bulk_action'	=> $action,
					'mark'			=> $ids,
					'q'				=> $search,
					'type'			=> $type,
				]));
			}
		}

		$result = $this->db->sql_query('SELECT COUNT(bounce_id) AS total FROM ' . $table . ' b' . $where);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		/** @var \phpbb\pagination $pagination */
		$pagination = $this->container->get('pagination');
		$start = $pagination->validate_start($start, self::PER_PAGE, $total);
		$base_url = $this->list_url($search, $type);
		$pagination->generate_template_pagination($base_url, 'pagination', 'start', $total, self::PER_PAGE, $start);

		$sql = 'SELECT b.*, u.username, u.user_colour
			FROM ' . $table . ' b
			LEFT JOIN ' . USERS_TABLE . ' u ON (u.user_id = b.user_id)'
			. $where . '
			ORDER BY b.bounce_time DESC, b.bounce_id DESC';
		$result = $this->db->sql_query_limit($sql, self::PER_PAGE, $start);

		/** @var dsn_parser $parser */
		$parser = $this->container->get('salvocortesiano.mailhealth.dsn_parser');

		// One query for the whole page instead of one per row
		$blocked = array_flip($this->manager->get_suppression_list());

		while ($row = $this->db->sql_fetchrow($result))
		{
			$is_hard = ((int) $row['bounce_type'] === dsn_parser::TYPE_HARD);

			$this->template->assign_block_vars('bounce', [
				'ID'			=> (int) $row['bounce_id'],
				'EMAIL'			=> $row['bounce_email'],
				'USERNAME'		=> $row['username'] ? get_username_string('full', (int) $row['user_id'], $row['username'], $row['user_colour']) : $this->language->lang('MAILHEALTH_NOT_MEMBER'),
				'TYPE'			=> $this->language->lang($is_hard ? 'MAILHEALTH_HARD' : 'MAILHEALTH_SOFT'),
				'REASON'		=> $this->language->lang($parser->describe($row['bounce_status'], $row['bounce_diagnostic'])),
				'S_HARD'		=> $is_hard,
				'STATUS'		=> $row['bounce_status'],
				'DIAGNOSTIC'	=> $row['bounce_diagnostic'],
				'TIME'			=> $this->user->format_date((int) $row['bounce_time']),
				'S_SUPPRESSED'	=> isset($blocked[strtolower($row['bounce_email'])]),
			]);
		}
		$this->db->sql_freeresult($result);

		$this->assign_chart();

		foreach ($this->manager->domain_stats(10) as $domain)
		{
			$this->template->assign_block_vars('domain', [
				'DOMAIN'	=> $domain['domain'],
				'HARD'		=> $domain['hard'],
				'SOFT'		=> $domain['soft'],
				'TOTAL'		=> $domain['total'],
				'ADDRESSES'	=> $domain['addresses'],
			]);
		}

		$this->template->assign_vars([
			'S_BOUNCE_MODE'	=> true,
			'TOTAL_ROWS'	=> $total,
			'SEARCH'		=> $search,
			'FILTER_TYPE'	=> $type,
			'U_LIST'		=> $base_url,
			'U_EXPORT'		=> $base_url . '&amp;export=csv&amp;hash=' . generate_link_hash('mailhealth_export'),
		]);
	}

	protected function bounce_where($search, $type)
	{
		$conditions = [];

		if ($search !== '')
		{
			$conditions[] = 'b.bounce_email ' . $this->db->sql_like_expression($this->db->get_any_char() . strtolower($search) . $this->db->get_any_char());
		}

		if (in_array((int) $type, [dsn_parser::TYPE_SOFT, dsn_parser::TYPE_HARD], true))
		{
			$conditions[] = 'b.bounce_type = ' . (int) $type;
		}

		return empty($conditions) ? '' : ' WHERE ' . implode(' AND ', $conditions);
	}

	protected function list_url($search, $type = 0, $state = 0)
	{
		$url = $this->u_action;

		if ($search !== '')
		{
			$url .= '&amp;q=' . urlencode($search);
		}

		if ($type)
		{
			$url .= '&amp;type=' . (int) $type;
		}

		if ($state)
		{
			$url .= '&amp;state=' . (int) $state;
		}

		return $url;
	}

	/**
	 * Twelve weeks of bounces as stacked CSS bars. No JavaScript, no external
	 * library: it has to render in any ACP style.
	 */
	protected function assign_chart()
	{
		$weeks = $this->manager->weekly_stats(12);
		$max = 1;

		foreach ($weeks as $week)
		{
			$max = max($max, $week['hard'] + $week['soft']);
		}

		$total_hard = 0;
		$total_soft = 0;

		foreach ($weeks as $week)
		{
			$total_hard += $week['hard'];
			$total_soft += $week['soft'];

			$this->template->assign_block_vars('week', [
				'LABEL'		=> $this->user->format_date($week['start'], 'd/m'),
				'TOOLTIP'	=> $this->language->lang('MAILHEALTH_CHART_TOOLTIP', $this->user->format_date($week['start'], 'd/m'), $this->user->format_date($week['start'] + 6 * 86400, 'd/m'), $week['hard'], $week['soft']),
				'HARD'		=> $week['hard'],
				'SOFT'		=> $week['soft'],
				'TOTAL'		=> $week['hard'] + $week['soft'],
				'H_HARD'	=> round($week['hard'] / $max * 100, 1),
				'H_SOFT'	=> round($week['soft'] / $max * 100, 1),
			]);
		}

		$this->template->assign_vars([
			'S_CHART_EMPTY'		=> ($total_hard + $total_soft) === 0,
			'CHART_MAX'			=> $max,
			'CHART_TOTAL_HARD'	=> $total_hard,
			'CHART_TOTAL_SOFT'	=> $total_soft,
		]);
	}

	protected function export_bounces($table, $where)
	{
		/** @var dsn_parser $parser */
		$parser = $this->container->get('salvocortesiano.mailhealth.dsn_parser');

		$sql = 'SELECT b.*, u.username
			FROM ' . $table . ' b
			LEFT JOIN ' . USERS_TABLE . ' u ON (u.user_id = b.user_id)'
			. $where . '
			ORDER BY b.bounce_time DESC';
		$result = $this->db->sql_query($sql);

		$rows = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = [
				date('Y-m-d H:i:s', (int) $row['bounce_time']),
				$row['bounce_email'],
				(string) $row['username'],
				((int) $row['bounce_type'] === dsn_parser::TYPE_HARD) ? 'hard' : 'soft',
				$this->language->lang($parser->describe($row['bounce_status'], $row['bounce_diagnostic'])),
				$row['bounce_status'],
				$row['bounce_diagnostic'],
			];
		}
		$this->db->sql_freeresult($result);

		$this->send_csv('mailhealth-bounces-' . date('Ymd') . '.csv', [
			$this->language->lang('MAILHEALTH_TIME'),
			$this->language->lang('MAILHEALTH_EMAIL'),
			$this->language->lang('MAILHEALTH_USER'),
			$this->language->lang('MAILHEALTH_TYPE'),
			$this->language->lang('MAILHEALTH_REASON'),
			$this->language->lang('MAILHEALTH_STATUS'),
			$this->language->lang('MAILHEALTH_DIAGNOSTIC'),
		], $rows);
	}

	/* =====================================================================
	 * Affected members (state machine)
	 * ================================================================== */

	protected function mode_users()
	{
		$this->tpl_name = 'acp_mailhealth_users';
		$this->page_title = $this->language->lang('ACP_MAILHEALTH_USERS');

		$table = $this->container->getParameter('salvocortesiano.mailhealth.table.users');

		$state = $this->request->variable('state', 0);
		$start = $this->request->variable('start', 0);

		$reset = $this->request->variable('reset', 0);

		if ($reset && !$this->request->is_set_post('cancel'))
		{
			if (confirm_box(true))
			{
				$this->manager->reset_user($reset);

				$this->container->get('log')->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_MAILHEALTH_USER_RESET', false, [$this->username_of($reset)]);

				trigger_error($this->language->lang('MAILHEALTH_USER_RESET_DONE') . adm_back_link($this->list_url('', 0, $state)));
			}

			confirm_box(false, $this->language->lang('MAILHEALTH_USER_RESET_CONFIRM'), build_hidden_fields(['reset' => $reset, 'state' => $state]));
		}

		$where = in_array($state, [manager::STATE_BOUNCED, manager::STATE_CHANGED, manager::STATE_CONFIRMED], true)
			? ' WHERE s.mh_state = ' . (int) $state
			: '';

		$result = $this->db->sql_query('SELECT COUNT(s.user_id) AS total FROM ' . $table . ' s' . $where);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		/** @var \phpbb\pagination $pagination */
		$pagination = $this->container->get('pagination');
		$start = $pagination->validate_start($start, self::PER_PAGE, $total);
		$base_url = $this->list_url('', 0, $state);
		$pagination->generate_template_pagination($base_url, 'pagination', 'start', $total, self::PER_PAGE, $start);

		$sql = 'SELECT s.*, u.username, u.user_colour, u.user_email, u.user_type
			FROM ' . $table . ' s
			LEFT JOIN ' . USERS_TABLE . ' u ON (u.user_id = s.user_id)'
			. $where . '
			ORDER BY s.mh_state_time DESC';
		$result = $this->db->sql_query_limit($sql, self::PER_PAGE, $start);

		while ($row = $this->db->sql_fetchrow($result))
		{
			$this->template->assign_block_vars('member', [
				'USERNAME'		=> $row['username'] ? get_username_string('full', (int) $row['user_id'], $row['username'], $row['user_colour']) : $this->language->lang('MAILHEALTH_USER_DELETED'),
				'STATE'			=> $this->language->lang('MAILHEALTH_STATE_' . (int) $row['mh_state']),
				'STATE_ID'		=> (int) $row['mh_state'],
				'BOUNCED_EMAIL'	=> $row['mh_bounced_email'],
				'NEW_EMAIL'		=> $row['mh_new_email'],
				'CURRENT_EMAIL'	=> (string) $row['user_email'],
				'S_INACTIVE'	=> ((int) $row['user_type'] === USER_INACTIVE),
				'TIME'			=> $this->user->format_date((int) $row['mh_state_time']),
				'U_RESET'		=> $base_url . '&amp;reset=' . (int) $row['user_id'],
			]);
		}
		$this->db->sql_freeresult($result);

		$this->template->assign_vars([
			'TOTAL_ROWS'		=> $total,
			'FILTER_STATE'		=> $state,
			'CONFIRM_DAYS'		=> (int) $this->config['mailhealth_confirm_days'],
		]);
	}

	protected function username_of($user_id)
	{
		$result = $this->db->sql_query('SELECT username FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int) $user_id);
		$name = (string) $this->db->sql_fetchfield('username');
		$this->db->sql_freeresult($result);

		return $name !== '' ? $name : '#' . (int) $user_id;
	}

	/* =====================================================================
	 * Check-up
	 * ================================================================== */

	protected function mode_check()
	{
		$this->tpl_name = 'acp_mailhealth_check';
		$this->page_title = $this->language->lang('ACP_MAILHEALTH_CHECK');

		/** @var diagnostics $diagnostics */
		$diagnostics = $this->container->get('salvocortesiano.mailhealth.diagnostics');
		/** @var \salvocortesiano\mailhealth\service\dns_check $dns */
		$dns = $this->container->get('salvocortesiano.mailhealth.dns_check');
		/** @var \salvocortesiano\mailhealth\service\imap_client $imap */
		$imap = $this->container->get('salvocortesiano.mailhealth.imap_client');

		if ($this->request->is_set_post('export_report'))
		{
			$this->check_form();

			$report = $diagnostics->build_report($diagnostics->run(), $dns->run());
			$this->send_download('mailhealth-checkup-' . date('Ymd-His') . '.txt', 'text/plain; charset=UTF-8', $report);
		}

		if ($this->request->is_set_post('test_connection'))
		{
			$this->check_form();

			$test = $diagnostics->test_connection();

			$this->template->assign_vars([
				'S_TEST_RUN'	=> true,
				'S_TEST_OK'		=> ($test['status'] === diagnostics::OK),
				'TEST_DETAIL'	=> $test['detail'],
			]);
		}

		// Manual run of the whole cron job, so the administrator does not
		// have to wait for the next tick to see whether the chain works.
		if ($this->request->is_set_post('run_now'))
		{
			$this->check_form();

			if (version_compare((string) $this->config['mailhealth_version'], diagnostics::SCHEMA_VERSION, '<'))
			{
				trigger_error($this->language->lang('MAILHEALTH_CHECK_UPDATE_HINT') . adm_back_link($this->u_action), E_USER_WARNING);
			}

			/** @var \salvocortesiano\mailhealth\cron\task\fetch_bounces $task */
			$task = $this->container->get('salvocortesiano.mailhealth.cron.fetch_bounces');
			$task->run();
			$stats = $task->get_last_stats();

			if (!$stats['connected'])
			{
				trigger_error($this->language->lang('MAILHEALTH_RUN_NO_MAILBOX', \salvocortesiano\mailhealth\service\imap_client::format_error($stats['error'], $this->language)) . adm_back_link($this->u_action), E_USER_WARNING);
			}

			$message = ($stats['examined'] === 0)
				? $this->language->lang('MAILHEALTH_RUN_EMPTY')
				: $this->language->lang('MAILHEALTH_RUN_DONE', $stats['examined'], $stats['bounces'], $stats['ignored']);

			if ($stats['stopped'])
			{
				$message .= '<br /><br />' . $this->language->lang('MAILHEALTH_RUN_PARTIAL', \salvocortesiano\mailhealth\cron\task\fetch_bounces::time_budget());
			}

			trigger_error($message . adm_back_link($this->u_action));
		}

		// Sends the member messages to the administrator himself, so he can
		// read exactly what a member would receive.
		if ($this->request->is_set_post('test_messages'))
		{
			$this->check_form();

			$me = [
				'user_id'	=> (int) $this->user->data['user_id'],
				'username'	=> $this->user->data['username'],
				'user_lang'	=> $this->user->data['user_lang'],
			];
			$email = (string) $this->user->data['user_email'];

			/** @var \salvocortesiano\mailhealth\service\notifier $notifier */
			$notifier = $this->container->get('salvocortesiano.mailhealth.notifier');
			$result = $notifier->send_test($me, $email);

			$lines = [
				$result['pm']
					? $this->language->lang('MAILHEALTH_TEST_PM_SENT', $me['username'])
					: $this->language->lang('MAILHEALTH_TEST_PM_SKIPPED'),
				$result['email']
					? $this->language->lang('MAILHEALTH_TEST_MAIL_SENT', $email)
					: $this->language->lang('MAILHEALTH_TEST_MAIL_SKIPPED'),
				$this->language->lang('MAILHEALTH_TEST_MESSAGES_LANG', $result['lang']),
			];

			trigger_error(implode('<br />', $lines) . adm_back_link($this->u_action));
		}

		if ($this->request->is_set_post('dns_check'))
		{
			$this->check_form();

			foreach ($dns->run() as $row)
			{
				$this->template->assign_block_vars('dns', [
					'LABEL'		=> $this->language->lang($row['label']),
					'DETAIL'	=> ($row['detail'] !== '' && $this->language->is_set($row['detail'])) ? $this->language->lang($row['detail']) : $row['detail'],
					'HINT'		=> $row['hint'] !== '' ? $this->language->lang($row['hint']) : '',
					'S_OK'		=> $row['status'] === diagnostics::OK,
					'S_WARN'	=> $row['status'] === diagnostics::WARN,
					'S_FAIL'	=> $row['status'] === diagnostics::FAIL,
				]);
			}

			$this->template->assign_var('S_DNS_RUN', true);
		}

		$rows = $diagnostics->run();
		$summary = $diagnostics->summarise($rows);

		$current_group = '';
		foreach ($rows as $row)
		{
			if ($row['group'] !== $current_group)
			{
				$current_group = $row['group'];
				$this->template->assign_block_vars('checkgroup', [
					'NAME' => $this->language->lang($current_group),
				]);
			}

			$this->template->assign_block_vars('checkgroup.item', [
				'LABEL'		=> $this->language->lang($row['label']),
				'DETAIL'	=> $row['detail'],
				'HINT'		=> $row['hint'],
				'S_OK'		=> $row['status'] === diagnostics::OK,
				'S_WARN'	=> $row['status'] === diagnostics::WARN,
				'S_FAIL'	=> $row['status'] === diagnostics::FAIL,
			]);
		}

		$this->template->assign_vars([
			'CHECK_OK'			=> $summary[diagnostics::OK],
			'CHECK_WARN'		=> $summary[diagnostics::WARN],
			'CHECK_FAIL'		=> $summary[diagnostics::FAIL],
			'S_IMAP_AVAILABLE'	=> $imap->is_available(),
			'S_DNS_AVAILABLE'	=> $dns->is_available(),
			'DNS_DOMAIN'		=> $dns->get_domain(),
		]);
	}

	/* =====================================================================
	 * Suppression list
	 * ================================================================== */

	protected function mode_suppress()
	{
		$this->tpl_name = 'acp_mailhealth_list';
		$this->page_title = $this->language->lang('ACP_MAILHEALTH_SUPPRESS');

		$table = $this->container->getParameter('salvocortesiano.mailhealth.table.suppress');

		$search = trim($this->request->variable('q', '', true));
		$start = $this->request->variable('start', 0);

		$where = ($search !== '')
			? ' WHERE suppress_email ' . $this->db->sql_like_expression($this->db->get_any_char() . strtolower($search) . $this->db->get_any_char())
			: '';

		if ($this->request->variable('export', '') === 'csv')
		{
			$this->check_export_hash();

			$result = $this->db->sql_query('SELECT * FROM ' . $table . $where . ' ORDER BY suppress_time DESC');
			$rows = [];
			while ($row = $this->db->sql_fetchrow($result))
			{
				$rows[] = [
					$row['suppress_email'],
					$this->language->lang($row['suppress_reason']),
					$row['suppress_manual'] ? 'manual' : 'auto',
					date('Y-m-d H:i:s', (int) $row['suppress_time']),
				];
			}
			$this->db->sql_freeresult($result);

			$this->send_csv('mailhealth-suppression-' . date('Ymd') . '.csv', [
				$this->language->lang('MAILHEALTH_EMAIL'),
				$this->language->lang('MAILHEALTH_REASON'),
				$this->language->lang('MAILHEALTH_ORIGIN'),
				$this->language->lang('MAILHEALTH_TIME'),
			], $rows);
		}

		if ($this->request->is_set_post('add_email'))
		{
			$this->check_form();

			$email = strtolower(trim($this->request->variable('new_email', '')));

			if (!filter_var($email, FILTER_VALIDATE_EMAIL))
			{
				trigger_error($this->language->lang('MAILHEALTH_EMAIL_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}

			$this->manager->suppress($email, 'MAILHEALTH_REASON_MANUAL', true);
			trigger_error($this->language->lang('MAILHEALTH_SUPPRESS_ADDED') . adm_back_link($this->u_action));
		}

		$remove = $this->request->variable('remove', '');

		if ($remove !== '' && !$this->request->is_set_post('cancel'))
		{
			if (confirm_box(true))
			{
				$this->manager->unsuppress($remove);
				trigger_error($this->language->lang('MAILHEALTH_SUPPRESS_REMOVED') . adm_back_link($this->u_action));
			}

			confirm_box(false, $this->language->lang('MAILHEALTH_SUPPRESS_REMOVE_CONFIRM'), build_hidden_fields(['remove' => $remove]));
		}

		$result = $this->db->sql_query('SELECT COUNT(suppress_id) AS total FROM ' . $table . $where);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		/** @var \phpbb\pagination $pagination */
		$pagination = $this->container->get('pagination');
		$start = $pagination->validate_start($start, self::PER_PAGE, $total);
		$base_url = $this->list_url($search);
		$pagination->generate_template_pagination($base_url, 'pagination', 'start', $total, self::PER_PAGE, $start);

		$result = $this->db->sql_query_limit('SELECT * FROM ' . $table . $where . ' ORDER BY suppress_time DESC', self::PER_PAGE, $start);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$this->template->assign_block_vars('suppressed', [
				'EMAIL'		=> $row['suppress_email'],
				'REASON'	=> $this->language->lang($row['suppress_reason']),
				'S_MANUAL'	=> (bool) $row['suppress_manual'],
				'TIME'		=> $this->user->format_date((int) $row['suppress_time']),
				'U_REMOVE'	=> $base_url . '&amp;remove=' . urlencode($row['suppress_email']),
			]);
		}
		$this->db->sql_freeresult($result);

		$this->template->assign_vars([
			'S_SUPPRESS_MODE'	=> true,
			'TOTAL_ROWS'		=> $total,
			'SEARCH'			=> $search,
			'U_LIST'			=> $base_url,
			'U_EXPORT'			=> $base_url . '&amp;export=csv&amp;hash=' . generate_link_hash('mailhealth_export'),
		]);
	}

	/* =====================================================================
	 * Helpers
	 * ================================================================== */

	protected function check_form()
	{
		if (!check_form_key(self::FORM_KEY))
		{
			trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
		}
	}

	protected function check_export_hash()
	{
		if (!check_link_hash($this->request->variable('hash', ''), 'mailhealth_export'))
		{
			trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
		}
	}

	/**
	 * CSV for spreadsheets set to European conventions: semicolon separator,
	 * UTF-8 with BOM so accented characters survive Excel.
	 *
	 * Cells starting with = + - @ are prefixed with an apostrophe: the
	 * diagnostic text comes from remote mail servers and must never be able
	 * to run as a formula when the file is opened.
	 */
	protected function send_csv($filename, array $header, array $rows)
	{
		$lines = [$this->csv_line($header)];

		foreach ($rows as $row)
		{
			$lines[] = $this->csv_line($row);
		}

		$this->send_download($filename, 'text/csv; charset=UTF-8', "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n");
	}

	protected function csv_line(array $cells)
	{
		return implode(';', array_map(function ($cell) {
			$cell = str_replace(["\r\n", "\r", "\n"], ' ', (string) $cell);

			if ($cell !== '' && strpos('=+-@', $cell[0]) !== false)
			{
				$cell = "'" . $cell;
			}

			return '"' . str_replace('"', '""', $cell) . '"';
		}, $cells));
	}

	protected function send_download($filename, $mime, $content)
	{
		header('Content-Type: ' . $mime);
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . strlen($content));
		header('Cache-Control: private, no-store');
		header('X-Content-Type-Options: nosniff');

		echo $content;

		garbage_collection();
		exit_handler();
	}
}
