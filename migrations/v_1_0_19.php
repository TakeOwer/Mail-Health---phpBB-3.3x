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
 * Private message notifications and verification of a new address.
 */
class v_1_0_19 extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['mailhealth_version'])
			&& version_compare($this->config['mailhealth_version'], '1.0.19', '>=');
	}

	public static function depends_on()
	{
		return ['\salvocortesiano\mailhealth\migrations\v_1_0_15'];
	}

	public function update_schema()
	{
		return [
			'add_columns' => [
				$this->table_prefix . 'mailhealth_users' => [
					'mh_token'		=> ['VCHAR:32', ''],
					'mh_token_time'	=> ['TIMESTAMP', 0],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_columns' => [
				$this->table_prefix . 'mailhealth_users' => ['mh_token', 'mh_token_time'],
			],
		];
	}

	public function update_data()
	{
		return [
			['config.add', ['mailhealth_pm_enabled', 1]],
			// 0 = the oldest founder
			['config.add', ['mailhealth_pm_sender', 0]],
			['config.add', ['mailhealth_verify_enabled', 1]],
			['config.update', ['mailhealth_version', '1.0.19']],
		];
	}
}
