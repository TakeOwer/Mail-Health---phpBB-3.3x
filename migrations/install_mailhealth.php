<?php
/**
 *
 * Mail Health. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\mailhealth\migrations;

/**
 * First installation: tables, configuration and ACP module.
 *
 * Everything lives in one migration because no earlier version of this
 * extension was ever enabled anywhere. Future changes go into their own
 * versioned migration depending on this one.
 */
class install_mailhealth extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['mailhealth_version']);
	}

	public static function depends_on()
	{
		return ['\phpbb\db\migration\data\v330\v330'];
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'mailhealth_bounces' => [
					'COLUMNS' => [
						'bounce_id'			=> ['UINT', null, 'auto_increment'],
						'user_id'			=> ['UINT', 0],
						'bounce_email'		=> ['VCHAR:100', ''],
						// 1 = soft (4.x.x), 2 = hard (5.x.x), 0 = unknown
						'bounce_type'		=> ['TINT:2', 0],
						'bounce_status'		=> ['VCHAR:10', ''],
						'bounce_diagnostic'	=> ['TEXT_UNI', ''],
						'bounce_time'		=> ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'bounce_id',
					'KEYS' => [
						'mh_user'	=> ['INDEX', 'user_id'],
						'mh_email'	=> ['INDEX', 'bounce_email'],
						'mh_time'	=> ['INDEX', 'bounce_time'],
					],
				],
				$this->table_prefix . 'mailhealth_suppress' => [
					'COLUMNS' => [
						'suppress_id'		=> ['UINT', null, 'auto_increment'],
						'suppress_email'	=> ['VCHAR:100', ''],
						'suppress_reason'	=> ['VCHAR:255', ''],
						'suppress_manual'	=> ['BOOL', 0],
						'suppress_time'		=> ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'suppress_id',
					'KEYS' => [
						'mh_sup_email' => ['UNIQUE', 'suppress_email'],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'mailhealth_bounces',
				$this->table_prefix . 'mailhealth_suppress',
			],
		];
	}

	public function update_data()
	{
		return [
			['config.add', ['mailhealth_version', '1.0.5']],
			['config.add', ['mailhealth_enabled', 0]],

			// Mailbox. The password is always stored encrypted, see service/crypto.php
			['config.add', ['mailhealth_imap_host', '']],
			['config.add', ['mailhealth_imap_port', 993]],
			['config.add', ['mailhealth_imap_ssl', 1]],
			['config.add', ['mailhealth_imap_folder', 'INBOX']],
			['config.add', ['mailhealth_imap_user', '']],
			['config.add', ['mailhealth_imap_pass', '']],
			['config.add', ['mailhealth_imap_delete', 1]],
			['config.add', ['mailhealth_batch_size', 100]],

			// Rules
			['config.add', ['mailhealth_hard_limit', 1]],
			['config.add', ['mailhealth_soft_limit', 5]],
			// Days after which a recorded bounce no longer counts
			['config.add', ['mailhealth_record_period', 90]],
			// 0 = log only, 1 = stop e-mail, 2 = deactivate account
			['config.add', ['mailhealth_action', 1]],
			['config.add', ['mailhealth_notify_admin', 1]],

			// Cron
			['config.add', ['mailhealth_cron_interval', 3600]],
			['config.add', ['mailhealth_last_run', 0, true]],

			// ACP module. The modes are created in this order and phpBB shows
			// them in the order they were created, so this is also the order
			// of the tabs.
			['module.add', ['acp', 'ACP_CAT_DOT_MODS', 'ACP_MAILHEALTH_TITLE']],
			['module.add', ['acp', 'ACP_MAILHEALTH_TITLE', [
				'module_basename'	=> '\salvocortesiano\mailhealth\acp\main_module',
				'modes'				=> ['settings', 'bounces', 'check', 'suppress'],
			]]],
		];
	}
}
