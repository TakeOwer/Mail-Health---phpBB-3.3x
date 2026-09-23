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
 * No schema change: records the version, so the check-up can tell whether
 * the database is in step with the files.
 */
class v_1_0_7 extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['mailhealth_version'])
			&& version_compare($this->config['mailhealth_version'], '1.0.7', '>=');
	}

	public static function depends_on()
	{
		return ['\salvocortesiano\mailhealth\migrations\v_1_0_6'];
	}

	public function update_data()
	{
		return [
			['config.update', ['mailhealth_version', '1.0.7']],
		];
	}
}
