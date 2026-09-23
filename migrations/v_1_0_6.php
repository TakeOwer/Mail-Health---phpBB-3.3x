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
 * Member state machine, alerts, DNS check, transport choice, Users tab.
 *
 * Written as a separate step on top of install_mailhealth so it applies the
 * same way on a clean board and on one where an earlier build was enabled.
 */
class v_1_0_6 extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['mailhealth_version'])
			&& version_compare($this->config['mailhealth_version'], '1.0.6', '>=');
	}

	public static function depends_on()
	{
		return ['\salvocortesiano\mailhealth\migrations\install_mailhealth'];
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'mailhealth_users' => [
					'COLUMNS' => [
						'user_id'			=> ['UINT', 0],
						// 1 = bounced, 2 = address changed, 3 = confirmed
						'mh_state'			=> ['TINT:2', 0],
						'mh_bounced_email'	=> ['VCHAR:100', ''],
						'mh_new_email'		=> ['VCHAR:100', ''],
						// Settings the member had before they were switched off
						'mh_prev_notify'	=> ['BOOL', 1],
						'mh_prev_massemail'	=> ['BOOL', 1],
						'mh_prev_type'		=> ['TINT:2', 0],
						'mh_state_time'		=> ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'user_id',
					'KEYS' => [
						'mh_state'		=> ['INDEX', 'mh_state'],
						'mh_bounced'	=> ['INDEX', 'mh_bounced_email'],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'mailhealth_users',
			],
		];
	}

	public function update_data()
	{
		return [
			// 0 = automatic, 1 = ext-imap, 2 = native socket client
			['config.add', ['mailhealth_imap_transport', 0]],

			// Bounces in 24 hours that trigger an alert, 0 = off
			['config.add', ['mailhealth_alert_limit', 20]],
			['config.add', ['mailhealth_alert_email', 1]],
			['config.add', ['mailhealth_alert_last', 0, true]],

			// Days a new address stays on probation before being confirmed
			['config.add', ['mailhealth_confirm_days', 30]],

			// Empty = try the common selectors
			['config.add', ['mailhealth_dkim_selector', '']],

			['config.update', ['mailhealth_version', '1.0.6']],

			// phpBB lists the modes of a module in the order they were
			// created. To put the new tab second-to-last instead of at the
			// end, the existing modes are removed and created again in the
			// intended order.
			['module.remove', ['acp', 'ACP_MAILHEALTH_TITLE', [
				'module_basename'	=> '\salvocortesiano\mailhealth\acp\main_module',
				'modes'				=> ['settings', 'bounces', 'check', 'suppress'],
			]]],
			['module.add', ['acp', 'ACP_MAILHEALTH_TITLE', [
				'module_basename'	=> '\salvocortesiano\mailhealth\acp\main_module',
				'modes'				=> ['settings', 'bounces', 'users', 'check', 'suppress'],
			]]],
		];
	}

	/**
	 * Written by hand because the remove-and-add of the modes above cannot be
	 * reversed automatically. Having a revert_data() also switches off the
	 * automatic reversal of the config.add steps, hence the explicit removals.
	 */
	public function revert_data()
	{
		return [
			['config.remove', ['mailhealth_imap_transport']],
			['config.remove', ['mailhealth_alert_limit']],
			['config.remove', ['mailhealth_alert_email']],
			['config.remove', ['mailhealth_alert_last']],
			['config.remove', ['mailhealth_confirm_days']],
			['config.remove', ['mailhealth_dkim_selector']],
			['module.remove', ['acp', 'ACP_MAILHEALTH_TITLE', [
				'module_basename'	=> '\salvocortesiano\mailhealth\acp\main_module',
				'modes'				=> ['users'],
			]]],
		];
	}
}
