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
 * Self check of the installation.
 *
 * Every row is one question with a plain answer: is this piece in place, and
 * if not, what does the administrator have to do about it. The checks that
 * touch the network (the IMAP login) are deliberately kept separate and run
 * only on request, so opening the page is always fast and harmless.
 */
class diagnostics
{
	const OK	= 'ok';
	const WARN	= 'warn';
	const FAIL	= 'fail';

	/**
	 * Version written by the newest migration. When the files are newer than
	 * the database - files uploaded over an enabled extension - phpBB has not
	 * run the migrations yet and the check-up says so first thing.
	 */
	const SCHEMA_VERSION = '1.0.19';

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\db\tools\tools_interface */
	protected $db_tools;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var crypto */
	protected $crypto;

	/** @var imap_client */
	protected $imap;

	/** @var dsn_parser */
	protected $parser;

	/** @var integration */
	protected $integration;

	/** @var string */
	protected $bounces_table;

	/** @var string */
	protected $suppress_table;

	/** @var string */
	protected $users_table;

	/** @var array */
	protected $rows = [];

	public function __construct(
		\phpbb\config\config $config,
		\phpbb\db\driver\driver_interface $db,
		\phpbb\db\tools\tools_interface $db_tools,
		\phpbb\language\language $language,
		crypto $crypto,
		imap_client $imap,
		dsn_parser $parser,
		integration $integration,
		$bounces_table,
		$suppress_table,
		$users_table
	)
	{
		$this->config = $config;
		$this->db = $db;
		$this->db_tools = $db_tools;
		$this->language = $language;
		$this->crypto = $crypto;
		$this->imap = $imap;
		$this->parser = $parser;
		$this->integration = $integration;
		$this->bounces_table = $bounces_table;
		$this->suppress_table = $suppress_table;
		$this->users_table = $users_table;
	}

	/**
	 * @return array Rows, each ['group', 'label', 'status', 'detail', 'hint']
	 */
	public function run()
	{
		$this->rows = [];

		$this->check_environment();
		$this->check_crypto();
		$this->check_configuration();
		$this->check_storage();
		$this->check_cron();
		$this->check_parser();
		$this->check_integrations();

		return $this->rows;
	}

	/**
	 * @return array ['ok' => int, 'warn' => int, 'fail' => int]
	 */
	public function summarise(array $rows)
	{
		$summary = [self::OK => 0, self::WARN => 0, self::FAIL => 0];

		foreach ($rows as $row)
		{
			$summary[$row['status']]++;
		}

		return $summary;
	}

	/**
	 * $hint may be a language key or an already composed sentence; both are
	 * accepted so callers with placeholders do not need a second path.
	 */
	protected function add($group, $label, $status, $detail = '', $hint = '')
	{
		$this->rows[] = [
			'group'		=> $group,
			'label'		=> $label,
			'status'	=> $status,
			'detail'	=> $detail,
			'hint'		=> ($hint !== '' && $this->language->is_set($hint)) ? $this->language->lang($hint) : $hint,
		];
	}

	protected function check_environment()
	{
		$group = 'MAILHEALTH_CHECK_GROUP_ENV';

		$installed = (string) $this->config['mailhealth_version'];

		if (version_compare($installed, self::SCHEMA_VERSION, '<'))
		{
			$this->add($group, 'MAILHEALTH_CHECK_UPDATE', self::FAIL,
				$this->language->lang('MAILHEALTH_CHECK_UPDATE_PENDING', $installed, self::SCHEMA_VERSION),
				'MAILHEALTH_CHECK_UPDATE_HINT'
			);
		}
		else
		{
			$this->add($group, 'MAILHEALTH_CHECK_UPDATE', self::OK, $installed);
		}

		$this->add($group, 'MAILHEALTH_CHECK_PHP', version_compare(PHP_VERSION, '7.2.0', '>=') ? self::OK : self::FAIL, PHP_VERSION);

		$this->add($group, 'MAILHEALTH_CHECK_PHPBB',
			phpbb_version_compare($this->config['version'], '3.3.0', '>=') ? self::OK : self::FAIL,
			(string) $this->config['version']
		);

		$transport = $this->imap->get_transport();

		if ($transport === imap_client::TRANSPORT_EXT)
		{
			$this->add($group, 'MAILHEALTH_CHECK_TRANSPORT', self::OK, $this->language->lang('MAILHEALTH_TRANSPORT_EXT'));
		}
		else if ($transport === imap_client::TRANSPORT_NATIVE)
		{
			$this->add($group, 'MAILHEALTH_CHECK_TRANSPORT', self::OK,
				$this->language->lang('MAILHEALTH_TRANSPORT_NATIVE'),
				imap_client::ext_available() ? '' : 'MAILHEALTH_CHECK_TRANSPORT_NATIVE_HINT'
			);
		}
		else
		{
			$this->add($group, 'MAILHEALTH_CHECK_TRANSPORT', self::FAIL,
				$this->language->lang('MAILHEALTH_CHECK_MISSING'),
				'MAILHEALTH_CHECK_TRANSPORT_NONE_HINT'
			);
		}

		$this->add($group, 'MAILHEALTH_CHECK_EMAIL_ENABLED',
			!empty($this->config['email_enable']) ? self::OK : self::WARN,
			$this->language->lang(!empty($this->config['email_enable']) ? 'YES' : 'NO'),
			!empty($this->config['email_enable']) ? '' : 'MAILHEALTH_CHECK_EMAIL_ENABLED_HINT'
		);
	}

	protected function check_crypto()
	{
		$group = 'MAILHEALTH_CHECK_GROUP_CRYPTO';
		$cipher = $this->crypto->get_cipher();

		if ($cipher === '')
		{
			$this->add($group, 'MAILHEALTH_CHECK_CIPHER', self::FAIL,
				$this->language->lang('MAILHEALTH_CHECK_MISSING'),
				'MAILHEALTH_CRYPTO_UNAVAILABLE'
			);
		}
		else
		{
			$this->add($group, 'MAILHEALTH_CHECK_CIPHER', self::OK, $cipher);
		}

		if ($this->crypto->key_in_config())
		{
			$this->add($group, 'MAILHEALTH_CHECK_KEY', self::OK, $this->language->lang('MAILHEALTH_KEY_IN_CONFIG'));
		}
		else if ($this->crypto->key_exists())
		{
			$this->add($group, 'MAILHEALTH_CHECK_KEY', self::OK, $this->crypto->get_key_display_path(), 'MAILHEALTH_CHECK_KEY_BACKUP_HINT');
		}
		else
		{
			$this->add($group, 'MAILHEALTH_CHECK_KEY', self::WARN,
				$this->language->lang('MAILHEALTH_KEY_PENDING')
			);
		}

		$stored = (string) $this->config['mailhealth_imap_pass'];

		if ($stored === '')
		{
			$this->add($group, 'MAILHEALTH_CHECK_PASSWORD', self::WARN,
				$this->language->lang('MAILHEALTH_CHECK_NOT_SET'),
				'MAILHEALTH_CHECK_PASSWORD_HINT'
			);
		}
		else if (!$this->crypto->is_encrypted($stored))
		{
			$this->add($group, 'MAILHEALTH_CHECK_PASSWORD', self::FAIL,
				$this->language->lang('MAILHEALTH_CHECK_PLAINTEXT'),
				'MAILHEALTH_PASS_PLAINTEXT'
			);
		}
		else if ($this->crypto->decrypt($stored) === false)
		{
			$this->add($group, 'MAILHEALTH_CHECK_PASSWORD', self::FAIL,
				$this->language->lang($this->crypto->get_error())
			);
		}
		else
		{
			$this->add($group, 'MAILHEALTH_CHECK_PASSWORD', self::OK, $this->language->lang('MAILHEALTH_CHECK_ENCRYPTED'));
		}
	}

	protected function check_configuration()
	{
		$group = 'MAILHEALTH_CHECK_GROUP_CONFIG';

		$this->add($group, 'MAILHEALTH_CHECK_ENABLED',
			$this->config['mailhealth_enabled'] ? self::OK : self::WARN,
			$this->language->lang($this->config['mailhealth_enabled'] ? 'YES' : 'NO'),
			$this->config['mailhealth_enabled'] ? '' : 'MAILHEALTH_CHECK_ENABLED_HINT'
		);

		$host = (string) $this->config['mailhealth_imap_host'];
		$user = (string) $this->config['mailhealth_imap_user'];
		$port = (int) $this->config['mailhealth_imap_port'];
		$security = (int) $this->config['mailhealth_imap_security'];

		if ($host === '' || $user === '')
		{
			$this->add($group, 'MAILHEALTH_CHECK_MAILBOX', self::FAIL, $this->language->lang('MAILHEALTH_CHECK_NOT_SET'), 'MAILHEALTH_CHECK_MAILBOX_HINT');
		}
		else
		{
			$this->add($group, 'MAILHEALTH_CHECK_MAILBOX', self::OK, $this->language->lang('MAILHEALTH_CHECK_MAILBOX_VALUE', $user, $host, $port));
		}

		// Port and security must agree: 993 speaks TLS from the first byte,
		// 143 speaks plain text first (then optionally STARTTLS).
		$security_name = $this->language->lang('MAILHEALTH_SECURITY_' . $security);

		if ($security === imap_native::SECURITY_SSL && $port === 143)
		{
			$this->add($group, 'MAILHEALTH_CHECK_SECURITY', self::FAIL, $security_name . ', ' . $port, 'MAILHEALTH_CHECK_SECURITY_SSL_143');
		}
		else if ($security !== imap_native::SECURITY_SSL && $port === 993)
		{
			$this->add($group, 'MAILHEALTH_CHECK_SECURITY', self::FAIL, $security_name . ', ' . $port, 'MAILHEALTH_CHECK_SECURITY_993');
		}
		else if ($security === imap_native::SECURITY_NONE)
		{
			$this->add($group, 'MAILHEALTH_CHECK_SECURITY', self::WARN, $security_name, 'MAILHEALTH_CHECK_SECURITY_NONE');
		}
		else
		{
			$this->add($group, 'MAILHEALTH_CHECK_SECURITY', self::OK, $security_name . ', ' . $port);
		}

		// Where do bounces actually go? phpBB puts the board address in the
		// envelope when it sends through SMTP, or through mail() with "force
		// from address"; with plain mail() the host decides, and bounces end
		// up in a system mailbox nobody reads.
		$board_email = strtolower((string) $this->config['board_email']);
		$uses_smtp = !empty($this->config['smtp_delivery']);
		$forced = !empty($this->config['email_force_sender']);

		if ($board_email === '')
		{
			$this->add($group, 'MAILHEALTH_CHECK_RETURN_PATH', self::FAIL, $this->language->lang('MAILHEALTH_CHECK_NOT_SET'), 'MAILHEALTH_CHECK_RETURN_PATH_UNSET');
		}
		else if (!$uses_smtp && !$forced)
		{
			$this->add($group, 'MAILHEALTH_CHECK_RETURN_PATH', self::FAIL,
				$this->language->lang('MAILHEALTH_CHECK_RETURN_PATH_HOST'),
				$this->language->lang('MAILHEALTH_CHECK_RETURN_PATH_HOST_HINT', $board_email)
			);
		}
		else if (strpos($user, '@') !== false && strtolower($user) !== $board_email)
		{
			$this->add($group, 'MAILHEALTH_CHECK_RETURN_PATH', self::WARN,
				$this->language->lang('MAILHEALTH_CHECK_RETURN_PATH_TO', $board_email),
				$this->language->lang('MAILHEALTH_CHECK_RETURN_PATH_MISMATCH', $board_email, $user)
			);
		}
		else
		{
			$this->add($group, 'MAILHEALTH_CHECK_RETURN_PATH', self::OK, $this->language->lang('MAILHEALTH_CHECK_RETURN_PATH_TO', $board_email));
		}

		$hard = (int) $this->config['mailhealth_hard_limit'];
		$soft = (int) $this->config['mailhealth_soft_limit'];

		$this->add($group, 'MAILHEALTH_CHECK_LIMITS',
			($soft > $hard) ? self::OK : self::WARN,
			$this->language->lang('MAILHEALTH_CHECK_LIMITS_VALUE', $hard, $soft),
			($soft > $hard) ? '' : 'MAILHEALTH_CHECK_LIMITS_HINT'
		);

		$action = (int) $this->config['mailhealth_action'];
		$this->add($group, 'MAILHEALTH_CHECK_ACTION', self::OK, $this->language->lang('MAILHEALTH_ACTION_' . $action));
	}

	protected function check_storage()
	{
		$group = 'MAILHEALTH_CHECK_GROUP_STORAGE';

		foreach (['MAILHEALTH_CHECK_TABLE_BOUNCES' => $this->bounces_table, 'MAILHEALTH_CHECK_TABLE_SUPPRESS' => $this->suppress_table, 'MAILHEALTH_CHECK_TABLE_USERS' => $this->users_table] as $label => $table)
		{
			if (!$this->db_tools->sql_table_exists($table))
			{
				$this->add($group, $label, self::FAIL, $table, 'MAILHEALTH_CHECK_TABLE_HINT');
				continue;
			}

			$result = $this->db->sql_query('SELECT COUNT(*) AS total FROM ' . $table);
			$total = (int) $this->db->sql_fetchfield('total');
			$this->db->sql_freeresult($result);

			$this->add($group, $label, self::OK, $this->language->lang('MAILHEALTH_CHECK_ROWS', $total));
		}

		// Most recent bounce: tells the administrator at a glance whether the
		// pipeline has ever actually produced anything.
		if ($this->db_tools->sql_table_exists($this->bounces_table))
		{
			$result = $this->db->sql_query_limit('SELECT bounce_time FROM ' . $this->bounces_table . ' ORDER BY bounce_time DESC', 1);
			$row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);

			$this->add($group, 'MAILHEALTH_CHECK_LAST_BOUNCE', self::OK,
				$row ? date('Y-m-d H:i', (int) $row['bounce_time']) : $this->language->lang('MAILHEALTH_NEVER')
			);
		}

		if ($this->db_tools->sql_table_exists($this->users_table))
		{
			$states = [manager::STATE_BOUNCED => 0, manager::STATE_CHANGED => 0, manager::STATE_CONFIRMED => 0];

			$result = $this->db->sql_query('SELECT mh_state, COUNT(user_id) AS total FROM ' . $this->users_table . ' GROUP BY mh_state');
			while ($row = $this->db->sql_fetchrow($result))
			{
				$states[(int) $row['mh_state']] = (int) $row['total'];
			}
			$this->db->sql_freeresult($result);

			$this->add($group, 'MAILHEALTH_CHECK_STATES', self::OK, $this->language->lang('MAILHEALTH_CHECK_STATES_VALUE',
				$states[manager::STATE_BOUNCED],
				$states[manager::STATE_CHANGED],
				$states[manager::STATE_CONFIRMED]
			));
		}
	}

	protected function check_cron()
	{
		$group = 'MAILHEALTH_CHECK_GROUP_CRON';

		$last = (int) $this->config['mailhealth_last_run'];
		$interval = (int) $this->config['mailhealth_cron_interval'];
		$started = (int) $this->config['mailhealth_run_started'];
		$finished = (int) $this->config['mailhealth_run_finished'];
		$seconds = (string) $this->config['mailhealth_run_seconds'];

		// Started but never finished, and not simply still running: the host
		// killed it (memory or time limit). Worth saying loudly - a task that
		// dies also holds phpBB's cron lock for an hour.
		if ($started > 0 && $finished < $started && $started < time() - 120)
		{
			$this->add($group, 'MAILHEALTH_CHECK_LAST_RUN', self::FAIL,
				$this->language->lang('MAILHEALTH_CHECK_RUN_CRASHED', date('Y-m-d H:i', $started)),
				'MAILHEALTH_CHECK_RUN_CRASHED_HINT'
			);
		}
		else if ($last === 0)
		{
			$this->add($group, 'MAILHEALTH_CHECK_LAST_RUN', self::WARN, $this->language->lang('MAILHEALTH_NEVER'), 'MAILHEALTH_CHECK_LAST_RUN_HINT');
		}
		else
		{
			$detail = ($finished > 0 && $seconds !== '')
				? $this->language->lang('MAILHEALTH_CHECK_RUN_DURATION', date('Y-m-d H:i', $last), $seconds)
				: date('Y-m-d H:i', $last);

			if ($last < time() - ($interval * 4))
			{
				$this->add($group, 'MAILHEALTH_CHECK_LAST_RUN', self::WARN, $detail, 'MAILHEALTH_CHECK_STALE_HINT');
			}
			else
			{
				$this->add($group, 'MAILHEALTH_CHECK_LAST_RUN', self::OK, $detail);
			}
		}

		$this->add($group, 'MAILHEALTH_CHECK_BUDGET', self::OK,
			$this->language->lang('MAILHEALTH_CHECK_BUDGET_VALUE', \salvocortesiano\mailhealth\cron\task\fetch_bounces::time_budget(), (int) @ini_get('max_execution_time'))
		);

		$this->add($group, 'MAILHEALTH_CHECK_CRON_TYPE', self::OK,
			$this->language->lang($this->config['use_system_cron'] ? 'MAILHEALTH_CHECK_CRON_SYSTEM' : 'MAILHEALTH_CHECK_CRON_BOARD'),
			$this->config['use_system_cron'] ? '' : 'MAILHEALTH_CHECK_CRON_BOARD_HINT'
		);

		$this->add($group, 'MAILHEALTH_CHECK_INTERVAL', self::OK, $this->language->lang('MAILHEALTH_CHECK_SECONDS', $interval));

		$limit = (int) $this->config['mailhealth_alert_limit'];
		$this->add($group, 'MAILHEALTH_CHECK_ALERT',
			$limit > 0 ? self::OK : self::WARN,
			$limit > 0 ? $this->language->lang('MAILHEALTH_CHECK_ALERT_VALUE', $limit) : $this->language->lang('MAILHEALTH_CHECK_ALERT_OFF'),
			$limit > 0 ? '' : 'MAILHEALTH_CHECK_ALERT_OFF_HINT'
		);
	}

	/**
	 * Runs the parser against a canned bounce. This is the one check that
	 * proves the moving part actually moves: if the sample is classified
	 * correctly, the recognition chain is intact on this PHP build.
	 */
	protected function check_parser()
	{
		$sample = "Return-Path: <>\r\n"
			. "Content-Type: multipart/report; report-type=delivery-status; boundary=\"b1\"\r\n"
			. "Subject: Mail delivery failed\r\n\r\n"
			. "--b1\r\nContent-Type: text/plain\r\n\r\nThis is an automatically generated message.\r\n\r\n"
			. "--b1\r\nContent-Type: message/delivery-status\r\n\r\n"
			. "Reporting-MTA: dns; mail.example.com\r\n\r\n"
			. "Final-Recipient: rfc822; nobody@example.com\r\n"
			. "Action: failed\r\n"
			. "Status: 5.1.1\r\n"
			. "Diagnostic-Code: smtp; 550 5.1.1 User unknown\r\n\r\n--b1--\r\n";

		$parsed = $this->parser->parse($sample);

		$ok = is_array($parsed)
			&& $parsed['email'] === 'nobody@example.com'
			&& (int) $parsed['type'] === dsn_parser::TYPE_HARD
			&& $parsed['status'] === '5.1.1';

		$this->add('MAILHEALTH_CHECK_GROUP_PARSER', 'MAILHEALTH_CHECK_PARSER',
			$ok ? self::OK : self::FAIL,
			$this->language->lang($ok ? 'MAILHEALTH_CHECK_PARSER_OK' : 'MAILHEALTH_CHECK_PARSER_FAIL'),
			$ok ? '' : 'MAILHEALTH_CHECK_PARSER_HINT'
		);
	}

	/**
	 * Other extensions Mail Health can work with. Never a failure: the board
	 * is perfectly usable without any of them.
	 */
	protected function check_integrations()
	{
		$group = 'MAILHEALTH_CHECK_GROUP_INTEGRATION';
		$state = $this->integration->get_state();

		if ($state === integration::STATE_ENABLED)
		{
			$this->add($group, 'MAILHEALTH_CHECK_NEWSLETTER', self::OK,
				$this->language->lang('MAILHEALTH_CHECK_NEWSLETTER_ENABLED'),
				$this->language->lang('MAILHEALTH_CHECK_NEWSLETTER_BLOCKED', $this->integration->count_blocked())
			);
		}
		else if ($state === integration::STATE_DISABLED)
		{
			$this->add($group, 'MAILHEALTH_CHECK_NEWSLETTER', self::WARN,
				$this->language->lang('MAILHEALTH_CHECK_NEWSLETTER_DISABLED'),
				'MAILHEALTH_CHECK_NEWSLETTER_DISABLED_HINT'
			);
		}
		else
		{
			$this->add($group, 'MAILHEALTH_CHECK_NEWSLETTER', self::OK,
				$this->language->lang('MAILHEALTH_CHECK_NEWSLETTER_MISSING'),
				'MAILHEALTH_CHECK_NEWSLETTER_MISSING_HINT'
			);
		}
	}

	/**
	 * Plain text version of the check-up, to attach to a support request.
	 * The mailbox user and host are kept, the password obviously never
	 * appears (only whether it is stored and readable).
	 *
	 * @param array      $rows     Output of run()
	 * @param array|null $dns_rows Output of dns_check::run(), if available
	 * @return string
	 */
	public function build_report(array $rows, $dns_rows = null)
	{
		$marks = [self::OK => '[ OK ]', self::WARN => '[ !! ]', self::FAIL => '[FAIL]'];
		$lines = [];

		$lines[] = 'Mail Health ' . $this->config['mailhealth_version'] . ' - ' . $this->language->lang('ACP_MAILHEALTH_CHECK');
		$lines[] = date('Y-m-d H:i:s T') . ' - phpBB ' . $this->config['version'] . ' - PHP ' . PHP_VERSION;
		$lines[] = str_repeat('=', 72);

		$summary = $this->summarise($rows);
		$lines[] = sprintf('OK: %d   !!: %d   FAIL: %d', $summary[self::OK], $summary[self::WARN], $summary[self::FAIL]);

		$group = '';
		foreach ($rows as $row)
		{
			if ($row['group'] !== $group)
			{
				$group = $row['group'];
				$lines[] = '';
				$lines[] = '## ' . $this->language->lang($group);
			}

			$lines[] = $marks[$row['status']] . ' ' . $this->language->lang($row['label']) . ': ' . $this->plain($row['detail']);

			if ($row['hint'] !== '')
			{
				$lines[] = '         ' . $this->plain($row['hint']);
			}
		}

		if (is_array($dns_rows))
		{
			$lines[] = '';
			$lines[] = '## ' . $this->language->lang('MAILHEALTH_CHECK_GROUP_DNS');

			foreach ($dns_rows as $row)
			{
				$detail = ($row['detail'] !== '' && $this->language->is_set($row['detail'])) ? $this->language->lang($row['detail']) : $row['detail'];
				$lines[] = $marks[$row['status']] . ' ' . $this->language->lang($row['label']) . ': ' . $this->plain($detail);

				if ($row['hint'] !== '')
				{
					$lines[] = '         ' . $this->plain($this->language->lang($row['hint']));
				}
			}
		}

		return implode("\r\n", $lines) . "\r\n";
	}

	protected function plain($text)
	{
		return html_entity_decode(strip_tags((string) $text), ENT_QUOTES, 'UTF-8');
	}

	/**
	 * Live login to the mailbox. Separate from run() because it goes over the
	 * network and can hang for several seconds.
	 *
	 * @return array ['status' => string, 'detail' => string]
	 */
	public function test_connection()
	{
		if (!$this->imap->connect())
		{
			return [
				'status'	=> self::FAIL,
				'detail'	=> $this->imap->get_error_message($this->language),
			];
		}

		$transport = $this->imap->get_transport_name();
		$this->imap->close(false);

		return ['status' => self::OK, 'detail' => $this->language->lang('MAILHEALTH_TEST_OK_VIA', $this->language->lang($transport === 'socket' ? 'MAILHEALTH_TRANSPORT_NATIVE' : 'MAILHEALTH_TRANSPORT_EXT'))];
	}
}
