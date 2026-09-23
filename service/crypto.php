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
 * Authenticated encryption for the credentials kept in phpbb_config.
 *
 * Threat model, stated plainly: this protects the stored password against
 * anyone who obtains a copy of the DATABASE only - a SQL dump, a backup
 * handed to hosting support, an injection in some other extension. It does
 * NOT protect against an attacker who already has filesystem access, because
 * the key has to live on the same server for the cron to run unattended.
 *
 * The key therefore never goes in the database. It is read, in order, from:
 *
 *   1. the MAILHEALTH_KEY constant in config.php (base64 of 32 bytes),
 *      for administrators who want the key on a separate, hand managed file;
 *   2. store/salvocortesiano_mailhealth/mailhealth_key.php, generated on
 *      first use. The store directory is already writable and already
 *      protected from web access by phpBB's own rules, and the file is a
 *      PHP script returning the key, so even a misconfigured web server
 *      serving it directly outputs nothing.
 *
 * Ciphertext format:  mh1:<cipher>:<base64 payload>
 * The prefix makes it possible to recognise an encrypted value, to tell an
 * encrypted one from a legacy plaintext one, and to migrate algorithms later.
 */
class crypto
{
	const PREFIX = 'mh1';
	const KEY_BYTES = 32;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	/** @var string|null Raw 32 byte key, loaded lazily */
	protected $key = null;

	/** @var string Language key of the last failure */
	protected $error = '';

	public function __construct($root_path, $php_ext)
	{
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	/**
	 * Which cipher this server can offer. Empty string means none.
	 *
	 * @return string 'sodium', 'openssl' or ''
	 */
	public function get_cipher()
	{
		if (function_exists('sodium_crypto_secretbox'))
		{
			return 'sodium';
		}

		if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true))
		{
			return 'openssl';
		}

		return '';
	}

	public function is_available()
	{
		return $this->get_cipher() !== '';
	}

	public function get_error()
	{
		return $this->error;
	}

	/**
	 * @param string $plaintext
	 * @return string|false The storable string, or false on failure
	 */
	public function encrypt($plaintext)
	{
		$cipher = $this->get_cipher();

		if ($cipher === '')
		{
			$this->error = 'MAILHEALTH_CRYPTO_UNAVAILABLE';
			return false;
		}

		$key = $this->get_key();

		if ($key === false)
		{
			return false;
		}

		if ($cipher === 'sodium')
		{
			$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
			$payload = $nonce . sodium_crypto_secretbox($plaintext, $nonce, $key);
		}
		else
		{
			$iv = random_bytes(12);
			$tag = '';
			$encrypted = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

			if ($encrypted === false)
			{
				$this->error = 'MAILHEALTH_CRYPTO_FAILED';
				return false;
			}

			$payload = $iv . $tag . $encrypted;
		}

		if (function_exists('sodium_memzero'))
		{
			sodium_memzero($plaintext);
		}

		return self::PREFIX . ':' . $cipher . ':' . base64_encode($payload);
	}

	/**
	 * @param string $stored
	 * @return string|false
	 */
	public function decrypt($stored)
	{
		$stored = (string) $stored;

		if ($stored === '')
		{
			return '';
		}

		if (!$this->is_encrypted($stored))
		{
			// A value written before this version, or hand edited in the
			// database. Return it so the board keeps working; the ACP will
			// prompt the administrator to save it again, which encrypts it.
			return $stored;
		}

		$parts = explode(':', $stored, 3);

		if (count($parts) !== 3)
		{
			$this->error = 'MAILHEALTH_CRYPTO_CORRUPT';
			return false;
		}

		list(, $cipher, $encoded) = $parts;
		$payload = base64_decode($encoded, true);

		if ($payload === false)
		{
			$this->error = 'MAILHEALTH_CRYPTO_CORRUPT';
			return false;
		}

		$key = $this->get_key(false);

		if ($key === false)
		{
			return false;
		}

		if ($cipher === 'sodium')
		{
			if (!function_exists('sodium_crypto_secretbox_open') || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)
			{
				$this->error = 'MAILHEALTH_CRYPTO_UNAVAILABLE';
				return false;
			}

			$nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
			$plaintext = sodium_crypto_secretbox_open(substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
		}
		else if ($cipher === 'openssl')
		{
			if (!function_exists('openssl_decrypt') || strlen($payload) <= 28)
			{
				$this->error = 'MAILHEALTH_CRYPTO_UNAVAILABLE';
				return false;
			}

			$iv = substr($payload, 0, 12);
			$tag = substr($payload, 12, 16);
			$plaintext = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
		}
		else
		{
			$this->error = 'MAILHEALTH_CRYPTO_UNAVAILABLE';
			return false;
		}

		if ($plaintext === false)
		{
			// Authentication failed: wrong key (restored backup, moved board,
			// lost store directory) or tampered ciphertext. Never guess.
			$this->error = 'MAILHEALTH_CRYPTO_BAD_KEY';
			return false;
		}

		return $plaintext;
	}

	public function is_encrypted($value)
	{
		return strpos((string) $value, self::PREFIX . ':') === 0;
	}

	public function get_key_path()
	{
		return $this->root_path . 'store/salvocortesiano_mailhealth/mailhealth_key.' . $this->php_ext;
	}

	/**
	 * Path as the administrator sees it on his FTP client, from the board
	 * root, without the ./../ the ACP adds to every path.
	 */
	public function get_key_display_path()
	{
		return 'store/salvocortesiano_mailhealth/mailhealth_key.' . $this->php_ext;
	}

	public function key_exists()
	{
		return defined('MAILHEALTH_KEY') || file_exists($this->get_key_path());
	}

	/**
	 * Is the key held in config.php rather than in store/?
	 */
	public function key_in_config()
	{
		return defined('MAILHEALTH_KEY');
	}

	/**
	 * @param bool $create Generate the key when it is missing. False when
	 *                     decrypting: a missing key there is an error, and
	 *                     creating a fresh one would silently destroy the
	 *                     ability to read what is already stored.
	 * @return string|false Raw key bytes
	 */
	public function get_key($create = true)
	{
		if ($this->key !== null)
		{
			return $this->key;
		}

		if (defined('MAILHEALTH_KEY'))
		{
			$key = base64_decode(MAILHEALTH_KEY, true);

			if ($key === false || strlen($key) !== self::KEY_BYTES)
			{
				$this->error = 'MAILHEALTH_CRYPTO_BAD_CONSTANT';
				return false;
			}

			return $this->key = $key;
		}

		$path = $this->get_key_path();

		if (file_exists($path))
		{
			$encoded = @include $path;
			$key = is_string($encoded) ? base64_decode($encoded, true) : false;

			if ($key === false || strlen($key) !== self::KEY_BYTES)
			{
				$this->error = 'MAILHEALTH_CRYPTO_BAD_KEYFILE';
				return false;
			}

			return $this->key = $key;
		}

		if (!$create)
		{
			$this->error = 'MAILHEALTH_CRYPTO_NO_KEY';
			return false;
		}

		return $this->create_key();
	}

	/**
	 * @return string|false
	 */
	protected function create_key()
	{
		$dir = dirname($this->get_key_path());

		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir))
		{
			$this->error = 'MAILHEALTH_CRYPTO_NO_STORE';
			return false;
		}

		$key = random_bytes(self::KEY_BYTES);
		$content = "<?php\n// Mail Health encryption key. Do not edit, do not share, do not lose.\n"
			. "return '" . base64_encode($key) . "';\n";

		if (@file_put_contents($this->get_key_path(), $content, LOCK_EX) === false)
		{
			$this->error = 'MAILHEALTH_CRYPTO_NO_STORE';
			return false;
		}

		@chmod($this->get_key_path(), 0600);

		return $this->key = $key;
	}
}
