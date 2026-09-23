<?php
/**
 *
 * Mail Health. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * A very small test framework. PHPUnit would need Composer, which shared
 * hosting rarely has; this runs anywhere PHP does.
 */

namespace phpbb\config
{
	class config implements \ArrayAccess
	{
		protected $values;

		public function __construct(array $values = [])
		{
			$this->values = $values;
		}

		public function offsetExists($key): bool
		{
			return isset($this->values[$key]);
		}

		#[\ReturnTypeWillChange]
		public function offsetGet($key)
		{
			return isset($this->values[$key]) ? $this->values[$key] : null;
		}

		public function offsetSet($key, $value): void
		{
			$this->values[$key] = $value;
		}

		public function offsetUnset($key): void
		{
			unset($this->values[$key]);
		}
	}
}

namespace
{
class mh_tests
{
	protected $passed = 0;
	protected $failed = 0;
	protected $skipped = 0;
	protected $failures = [];

	public function group($name)
	{
		echo "\n" . $name . "\n" . str_repeat('-', strlen($name)) . "\n";
	}

	public function ok($condition, $what)
	{
		$this->record((bool) $condition, $what, '');
	}

	public function same($expected, $actual, $what)
	{
		$this->record($expected === $actual, $what, 'atteso ' . $this->show($expected) . ', ottenuto ' . $this->show($actual));
	}

	public function skip($why)
	{
		$this->skipped++;
		echo "  -  " . $why . "\n";
	}

	protected function record($passed, $what, $detail)
	{
		if ($passed)
		{
			$this->passed++;
			echo "  OK   " . $what . "\n";
			return;
		}

		$this->failed++;
		$this->failures[] = $what . ($detail !== '' ? ' (' . $detail . ')' : '');
		echo "  FAIL " . $what . ($detail !== '' ? "\n       " . $detail : '') . "\n";
	}

	protected function show($value)
	{
		if (is_array($value))
		{
			return 'array(' . count($value) . ')';
		}

		if (is_bool($value))
		{
			return $value ? 'true' : 'false';
		}

		return is_string($value) ? "'" . (strlen($value) > 40 ? substr($value, 0, 40) . '…' : $value) . "'" : var_export($value, true);
	}

	/**
	 * @return int Exit code
	 */
	public function summary()
	{
		echo "\n" . str_repeat('=', 60) . "\n";
		printf("Superati: %d   Falliti: %d   Saltati: %d\n", $this->passed, $this->failed, $this->skipped);

		foreach ($this->failures as $failure)
		{
			echo "  FALLITO: " . $failure . "\n";
		}

		echo $this->failed === 0 ? "TUTTO A POSTO\n" : "CI SONO ERRORI\n";

		return $this->failed === 0 ? 0 : 1;
	}
}

/**
 * Language keys defined in a language file, sorted.
 *
 * @return array
 */
function mh_lang_keys($file)
{
	if (!file_exists($file))
	{
		return ['(file mancante: ' . basename($file) . ')'];
	}

	preg_match_all("/^\s*'([A-Z0-9_]+)'\s*=>/m", file_get_contents($file), $m);
	$keys = $m[1];
	sort($keys);

	return $keys;
}

	/**
	 * Language strings of a file, key => text.
	 *
	 * @return array
	 */
	function mh_lang_strings($file)
	{
		if (!file_exists($file))
		{
			return [];
		}

		preg_match_all("/^\s*'([A-Z0-9_]+)'\s*=>\s*'((?:[^'\\\\]|\\\\.)*)'/m", file_get_contents($file), $m, PREG_SET_ORDER);
		$strings = [];

		foreach ($m as $match)
		{
			$strings[$match[1]] = $match[2];
		}

		ksort($strings);

		return $strings;
	}

	/**
	 * Placeholders such as %s, %d, %1$s, sorted.
	 *
	 * @return array
	 */
	function mh_placeholders($text)
	{
		preg_match_all('/%\d*\$?[sd]/', $text, $m);
		sort($m[0]);

		return $m[0];
	}

	/**
	 * Variables such as {USERNAME} in an e-mail template, sorted.
	 *
	 * @return array
	 */
	function mh_mail_vars($file)
	{
		preg_match_all('/\{([A-Z_]+)\}/', file_exists($file) ? file_get_contents($file) : '', $m);
		$vars = array_values(array_unique($m[1]));
		sort($vars);

		return $vars;
	}

	/**
	 * Language object that returns the key and its arguments instead of a
	 * sentence: the wording is checked elsewhere, here what matters is which
	 * text was chosen and with what.
	 */
	class mh_fake_language
	{
		public $iso;

		public function __construct($iso)
		{
			$this->iso = $iso;
		}

		public function lang()
		{
			$args = func_get_args();

			return implode('|', $args);
		}

		public function is_set($key)
		{
			return true;
		}

		public function add_lang($component, $extension = null)
		{
		}
	}

	/**
	 * The real notifier with only the two delivery steps replaced: every
	 * decision - language, recipient, which text, whether to send at all -
	 * is the production code.
	 */
	class mh_notifier_probe extends salvocortesiano\mailhealth\service\notifier
	{
		public $mails = [];
		public $pms = [];

		public function __construct(array $config)
		{
			$this->config = new \phpbb\config\config($config);
			$this->root_path = './';
			$this->php_ext = 'php';
		}

		protected function language_for($iso)
		{
			return new mh_fake_language($iso !== '' ? $iso : (string) $this->config['default_lang']);
		}

		protected function deliver_email($to, $username, $iso, array $vars)
		{
			$this->mails[] = ['to' => $to, 'username' => $username, 'lang' => $iso, 'vars' => $vars];

			return true;
		}

		protected function deliver_pm(array $sender, $user_id, $subject, $body)
		{
			$this->pms[] = ['to' => $user_id, 'subject' => $subject, 'body' => $body];

			return true;
		}

		// The sender lives in the database; here any founder will do
		protected function get_sender()
		{
			return ['user_id' => 2, 'username' => 'Fondatore'];
		}

		// The link is built by phpBB's router
		protected function routing_link($user_id, $token)
		{
			return 'https://forum.example/app.php/mailhealth/confirm/' . $user_id . '/' . $token;
		}

		protected function board_url($path)
		{
			return 'https://forum.example/' . $path;
		}
	}

/**
 * Reaches the DNS rules without touching the network: the TXT lookup is
 * replaced, everything else is the real code.
 */
class mh_dns_probe extends salvocortesiano\mailhealth\service\dns_check
{
	public $records = [];

	public function __construct()
	{
		// The parent needs a config object only for the settings it reads
		parent::__construct(new mh_fake_config(['board_email' => 'info@example.com', 'mailhealth_dkim_selector' => '', 'mailhealth_imap_user' => '']));
	}

	protected function txt($name)
	{
		return isset($this->records[$name]) ? $this->records[$name] : [];
	}

	public function spf($records)
	{
		$this->records['example.com'] = (array) $records;

		return $this->check_spf('example.com');
	}

	public function dmarc($record)
	{
		$this->records['_dmarc.example.com'] = (array) $record;

		return $this->check_dmarc('example.com');
	}

	public function selector($value)
	{
		return self::normalize_selector($value);
	}
}

/**
 * Reaches the IMAP quoting rules without opening a socket.
 */
class mh_imap_probe extends salvocortesiano\mailhealth\service\imap_native
{
	public function quote($value)
	{
		return $this->astring($value);
	}
}

	class mh_fake_config extends \phpbb\config\config
	{
	}
}
