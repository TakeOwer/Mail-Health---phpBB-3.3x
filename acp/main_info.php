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

class main_info
{
	public function module()
	{
		return [
			'filename'	=> '\salvocortesiano\mailhealth\acp\main_module',
			'title'		=> 'ACP_MAILHEALTH_TITLE',
			'modes'		=> [
				'settings'	=> [
					'title'	=> 'ACP_MAILHEALTH_SETTINGS',
					'auth'	=> 'ext_salvocortesiano/mailhealth && acl_a_board',
					'cat'	=> ['ACP_MAILHEALTH_TITLE'],
				],
				'bounces'	=> [
					'title'	=> 'ACP_MAILHEALTH_BOUNCES',
					'auth'	=> 'ext_salvocortesiano/mailhealth && acl_a_board',
					'cat'	=> ['ACP_MAILHEALTH_TITLE'],
				],
				'users'		=> [
					'title'	=> 'ACP_MAILHEALTH_USERS',
					'auth'	=> 'ext_salvocortesiano/mailhealth && acl_a_board',
					'cat'	=> ['ACP_MAILHEALTH_TITLE'],
				],
				'check'		=> [
					'title'	=> 'ACP_MAILHEALTH_CHECK',
					'auth'	=> 'ext_salvocortesiano/mailhealth && acl_a_board',
					'cat'	=> ['ACP_MAILHEALTH_TITLE'],
				],
				'suppress'	=> [
					'title'	=> 'ACP_MAILHEALTH_SUPPRESS',
					'auth'	=> 'ext_salvocortesiano/mailhealth && acl_a_board',
					'cat'	=> ['ACP_MAILHEALTH_TITLE'],
				],
			],
		];
	}
}
