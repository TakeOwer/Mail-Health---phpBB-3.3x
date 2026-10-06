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
 * Remembers a member's e-mail notification settings while they are switched
 * off, so they can be restored exactly as they were.
 */
class v_1_0_26 extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['mailhealth_version'])
			&& version_compare($this->config['mailhealth_version'], '1.0.26', '>=');
	}

	public static function depends_on()
	{
		return ['\salvocortesiano\mailhealth\migrations\v_1_0_19'];
	}

	public function update_schema()
	{
		return [
			'add_columns' => [
				$this->table_prefix . 'mailhealth_users' => [
					'mh_prev_notify_rows'	=> ['TEXT_UNI', ''],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_columns' => [
				$this->table_prefix . 'mailhealth_users' => ['mh_prev_notify_rows'],
			],
		];
	}

	public function update_data()
	{
		return [
			['config.update', ['mailhealth_version', '1.0.26']],
		];
	}
}
