<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use PDO;
use PDOException;

/**
 * OAuth data: registered clients, authorization codes and access/refresh token pairs.
 * Codes and tokens are only stored as SHA-256 hashes.
 * @author d.hilverda <dimitri.hilverda@moving-in.nl>
 * @date 06-10-2026
 */
final class OAuthStore
{
	const string CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

	const int CODE_SECONDS = 60;

	const int ACCESS_SECONDS = 3600;

	const int REFRESH_DAYS = 30;

	const int MAX_UNUSED_CLIENTS = 500;

	const int MAX_CLIENTS_PER_ADDRESS = 20;

	/**
	 * @param PDO $pdo
	 */
	public function __construct(private PDO $pdo)
	{
	}

	/**
	 * Only Claude's own callback, or a loopback /callback (Claude Code) on any port.
	 * @param string $uri
	 * @return bool
	 */
	public static function allowedRedirect(string $uri): bool
	{
		return $uri === self::CLAUDE_CALLBACK || self::loopback($uri) !== null;
	}

	/**
	 * @param string $registered
	 * @param string $given
	 * @return bool Exact match; for loopback the port may differ (RFC 8252).
	 */
	public static function redirectMatches(string $registered, string $given): bool
	{
		if($registered === $given) {
			return true;
		}
		$a = self::loopback($registered);
		$b = self::loopback($given);

		return $a !== null && $a === $b;
	}

	/**
	 * @param string $uri
	 * @return string|null "http://host/callback" without the port, or null when it is not a loopback callback.
	 */
	private static function loopback(string $uri): ?string
	{
		if(preg_match('#^http://(localhost|127\.0\.0\.1)(:[0-9]{1,5})?/callback\z#', $uri, $m) !== 1) {
			return null;
		}

		return 'http://' . $m[1] . '/callback';
	}

	/**
	 * Whether a new registration is allowed: at most MAX_CLIENTS_PER_ADDRESS per address per hour,
	 * and at most MAX_UNUSED_CLIENTS unused clients younger than a day in total.
	 * @param string $ip
	 * @return int 0 = allowed, else the HTTP status to refuse with (429 per address, 503 in total).
	 */
	public function registrationRefusal(string $ip): int
	{
		$st = $this->pdo->prepare("SELECT count(*) FROM oauth_client WHERE ip_hash = ? AND created_at > now() - interval '1 hour'");
		$st->execute(array(Auth::hash($ip)));
		if((int) $st->fetchColumn() >= self::MAX_CLIENTS_PER_ADDRESS) {
			return 429;
		}
		$unused = (int) $this->pdo->query(
			"SELECT count(*) FROM oauth_client c WHERE c.created_at > now() - interval '1 day'
				AND NOT EXISTS (SELECT 1 FROM oauth_token t WHERE t.client_id = c.id)"
		)->fetchColumn();

		return $unused >= self::MAX_UNUSED_CLIENTS ? 503 : 0;
	}

	/**
	 * @param string $name
	 * @param list<string> $redirectUris Already checked with allowedRedirect().
	 * @param string $ip Registering address; only its hash is stored.
	 * @return string The new client id.
	 */
	public function createClient(string $name, array $redirectUris, string $ip): string
	{
		$id = bin2hex(random_bytes(16));
		$this->pdo->prepare('INSERT INTO oauth_client (id, name, redirect_uris, ip_hash) VALUES (?, ?, CAST(? AS jsonb), ?)')
			->execute(array($id, $name, json_encode($redirectUris, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), Auth::hash($ip)));

		return $id;
	}

	/**
	 * @param string $id
	 * @return array{id:string,name:string,redirect_uris:list<string>}|null
	 */
	public function client(string $id): ?array
	{
		$st = $this->pdo->prepare('SELECT id, name, redirect_uris FROM oauth_client WHERE id = ?');
		$st->execute(array($id));
		$row = $st->fetch();
		if($row === false) {
			return null;
		}

		return array('id' => (string) $row['id'], 'name' => (string) $row['name'], 'redirect_uris' => json_decode((string) $row['redirect_uris'], true));
	}

	/**
	 * @param string $clientId
	 * @param int $personId
	 * @param string $redirectUri
	 * @param string $codeChallenge
	 * @return string The code (only its hash is stored).
	 */
	public function createCode(string $clientId, int $personId, string $redirectUri, string $codeChallenge): string
	{
		$code = bin2hex(random_bytes(32));
		$this->pdo->prepare(
			"INSERT INTO oauth_code (code_hash, family, client_id, person_id, redirect_uri, code_challenge, expires_at)
			 VALUES (?, ?, ?, ?, ?, ?, now() + make_interval(secs => ?))"
		)->execute(array(Auth::hash($code), bin2hex(random_bytes(16)), $clientId, $personId, $redirectUri, $codeChallenge, self::CODE_SECONDS));

		return $code;
	}

	/**
	 * Redeems a code for a token pair: unused, not expired, same client and redirect, PKCE S256.
	 * A replayed code revokes the tokens that were issued from it (the code may have leaked).
	 * @param string $code
	 * @param string $clientId
	 * @param string $redirectUri
	 * @param string $verifier
	 * @return array{access_token:string,refresh_token:string}|null null = invalid_grant.
	 */
	public function redeemCode(string $code, string $clientId, string $redirectUri, string $verifier): ?array
	{
		$this->pdo->beginTransaction();
		try {
			$st = $this->pdo->prepare(
				'SELECT c.family, c.client_id, c.person_id, c.redirect_uri, c.code_challenge, c.used_at IS NOT NULL AS used, c.expires_at > now() AS usable
				 FROM oauth_code c JOIN person p ON p.id = c.person_id AND p.active
				 WHERE c.code_hash = ? FOR UPDATE OF c'
			);
			$st->execute(array(Auth::hash($code)));
			$row = $st->fetch();
			if($row === false || $row['used'] === true) {
				if($row !== false) {
					$this->pdo->prepare('UPDATE oauth_token SET revoked_at = coalesce(revoked_at, now()) WHERE family = ?')->execute(array($row['family']));
				}
				$this->pdo->commit();

				return null;
			}
			$this->pdo->prepare('UPDATE oauth_code SET used_at = coalesce(used_at, now()) WHERE code_hash = ?')->execute(array(Auth::hash($code)));
			$valid = $row['usable'] === true
				&& hash_equals((string) $row['client_id'], $clientId)
				&& hash_equals((string) $row['redirect_uri'], $redirectUri)
				&& hash_equals((string) $row['code_challenge'], self::challenge($verifier));
			$tokens = $valid === true ? $this->issue((int) $row['person_id'], $clientId, (string) $row['family']) : null;
			$this->pdo->commit();

			return $tokens;
		} catch(PDOException $e) {
			$this->pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * Rotates a refresh token. Reusing an already rotated one revokes the whole family.
	 * @param string $refreshToken
	 * @param string $clientId
	 * @return array{access_token:string,refresh_token:string}|null null = invalid_grant.
	 */
	public function refresh(string $refreshToken, string $clientId): ?array
	{
		$this->pdo->beginTransaction();
		try {
			$st = $this->pdo->prepare(
				'SELECT t.id, t.family, t.person_id, t.client_id, t.rotated_at IS NOT NULL AS rotated,
					t.revoked_at IS NULL AND t.refresh_expires_at > now() AND p.active AS usable
				 FROM oauth_token t JOIN person p ON p.id = t.person_id
				 WHERE t.refresh_hash = ? FOR UPDATE OF t'
			);
			$st->execute(array(Auth::hash($refreshToken)));
			$row = $st->fetch();
			$tokens = null;
			if($row !== false && $row['rotated'] === true) {
				//Reuse of an old refresh token: someone else may have it, end the whole family:
				$this->pdo->prepare('UPDATE oauth_token SET revoked_at = coalesce(revoked_at, now()) WHERE family = ?')->execute(array($row['family']));
			} elseif($row !== false && $row['usable'] === true && hash_equals((string) $row['client_id'], $clientId) === true) {
				$this->pdo->prepare('UPDATE oauth_token SET rotated_at = now() WHERE id = ?')->execute(array($row['id']));
				$tokens = $this->issue((int) $row['person_id'], $clientId, (string) $row['family']);
			}
			$this->pdo->commit();

			return $tokens;
		} catch(PDOException $e) {
			$this->pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * @param string|null $accessToken
	 * @return array{id:int,name:string}|null The person of a valid, current access token.
	 */
	public function personForAccessToken(?string $accessToken): ?array
	{
		if($accessToken === null || $accessToken === '') {
			return null;
		}
		$st = $this->pdo->prepare(
			'SELECT p.id, p.name FROM oauth_token t JOIN person p ON p.id = t.person_id
			 WHERE t.access_hash = ? AND t.revoked_at IS NULL AND t.rotated_at IS NULL AND t.access_expires_at > now() AND p.active'
		);
		$st->execute(array(Auth::hash($accessToken)));
		$row = $st->fetch();

		return $row === false ? null : array('id' => (int) $row['id'], 'name' => (string) $row['name']);
	}

	/**
	 * @param string $verifier
	 * @return string The S256 code challenge of a verifier ('' for an invalid verifier).
	 */
	public static function challenge(string $verifier): string
	{
		if(preg_match('/^[A-Za-z0-9._~-]{43,128}\z/', $verifier) !== 1) {
			return '';
		}

		return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
	}

	/**
	 * @param int $personId
	 * @param string $clientId
	 * @param string $family
	 * @return array{access_token:string,refresh_token:string}
	 */
	private function issue(int $personId, string $clientId, string $family): array
	{
		$access = bin2hex(random_bytes(32));
		$refresh = bin2hex(random_bytes(32));
		$this->pdo->prepare(
			"INSERT INTO oauth_token (family, access_hash, refresh_hash, person_id, client_id, access_expires_at, refresh_expires_at)
			 VALUES (?, ?, ?, ?, ?, now() + make_interval(secs => ?), now() + make_interval(days => ?))"
		)->execute(array($family, Auth::hash($access), Auth::hash($refresh), $personId, $clientId, self::ACCESS_SECONDS, self::REFRESH_DAYS));

		return array('access_token' => $access, 'refresh_token' => $refresh);
	}
}
