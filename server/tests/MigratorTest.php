<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use PDO;
use Relay\Migrator;

/**
 * Tests the migrations: a fresh database and an upgraded old Dutch database end up identical.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class MigratorTest extends DbTestCase
{
	const array ALL = array('001_schema.sql', '002_english_names.sql', '003_teams.sql', '004_oauth.sql');

	/**
	 * @return void
	 */
	public function testMigratesOnce(): void
	{
		$this->pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public');
		$migrator = $this->migrator();

		self::assertSame(self::ALL, $migrator->migrate());
		self::assertSame(array(), $migrator->migrate());
		self::assertSame(
			array('message', 'message_read', 'migration', 'oauth_client', 'oauth_code', 'oauth_token', 'person', 'relay_secret', 'session', 'team', 'team_member'),
			$this->pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN)
		);
		self::assertSame(self::ALL, $this->pdo->query('SELECT name FROM migration ORDER BY name')->fetchAll(PDO::FETCH_COLUMN));
	}

	/**
	 * @return void
	 */
	public function testFreshSchemaHasTheSpecifiedColumns(): void
	{
		$columns = array();
		foreach($this->pdo->query("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = 'public' ORDER BY table_name, ordinal_position") as $row) {
			$columns[$row['table_name']][] = $row['column_name'];
		}

		self::assertSame(array('id', 'name', 'token_hash', 'active'), $columns['person']);
		self::assertSame(array('id', 'name', 'created_at'), $columns['team']);
		self::assertSame(array('team_id', 'person_id'), $columns['team_member']);
		self::assertEqualsCanonicalizing(
			array('name', 'person_id', 'team', 'machine', 'repo', 'repo_base', 'branch', 'ticket', 'claim', 'started_at', 'last_seen'),
			$columns['session']
		);
		self::assertEqualsCanonicalizing(
			array('id', 'from_session', 'from_person_id', 'to_session', 'to_person_id', 'team', 'kind', 'text', 'reply_to', 'created_at'),
			$columns['message']
		);
		self::assertSame(array('message_id', 'session', 'read_at'), $columns['message_read']);
		self::assertSame(array('name', 'executed_at'), $columns['migration']);
	}

	/**
	 * @return void
	 */
	public function testNoDutchNamesAreLeft(): void
	{
		$names = $this->schemaNames();
		$dutch = array_filter($names, static fn(string $name): bool => preg_match('/persoon|sessie|bericht|naam|actief|basis|sinds|laatst|gezien|soort|tekst|antwoord|aangemaakt|gelezen|migratie|uitgevoerd|ruimte|\bvan_|\baan_/', $name) === 1);
		self::assertSame(array(), array_values($dutch));
	}

	/**
	 * @return void
	 */
	public function testUpgradedDutchDatabaseEqualsFreshDatabase(): void
	{
		$fresh = $this->schemaDump();

		$this->createDutchDatabase(true, false);
		$this->migrator()->migrate();

		self::assertSame($fresh, $this->schemaDump());
	}

	/**
	 * @return void
	 */
	public function testUpgradeWithRuimteColumnAlsoEqualsFreshDatabase(): void
	{
		$fresh = $this->schemaDump();

		$this->createDutchDatabase(true, true);
		$this->migrator()->migrate();

		self::assertSame($fresh, $this->schemaDump());
	}

	/**
	 * @return void
	 */
	public function testUpgradeKeepsDataInTeamDefault(): void
	{
		$this->createDutchDatabase(true, false);
		self::assertSame(array('002_english_names.sql', '003_teams.sql', '004_oauth.sql'), $this->migrator()->migrate());

		//Bookkeeping was renamed, the old row kept:
		self::assertSame(self::ALL, $this->pdo->query('SELECT name FROM migration ORDER BY name')->fetchAll(PDO::FETCH_COLUMN));

		//Persons, team default with every person:
		self::assertSame(array('Alice', 'Bob'), $this->pdo->query('SELECT name FROM person ORDER BY name')->fetchAll(PDO::FETCH_COLUMN));
		self::assertSame(array('default'), $this->pdo->query('SELECT name FROM team')->fetchAll(PDO::FETCH_COLUMN));
		self::assertSame(
			array('Alice', 'Bob'),
			$this->pdo->query('SELECT p.name FROM team_member m JOIN team t ON t.id = m.team_id JOIN person p ON p.id = m.person_id WHERE t.name = \'default\' ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN)
		);

		//Sessions and messages in team default, kinds translated:
		self::assertSame(
			array(array('alice-1', 'default', 'app'), array('bob-1', 'default', 'app')),
			$this->pdo->query('SELECT name, team, repo_base FROM session ORDER BY name')->fetchAll(PDO::FETCH_NUM)
		);
		self::assertSame(
			array(array('note', 'default', 'hi'), array('question', 'default', 'why?'), array('answer', 'default', 'because')),
			$this->pdo->query('SELECT kind, team, text FROM message ORDER BY id')->fetchAll(PDO::FETCH_NUM)
		);
		self::assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM message_read')->fetchColumn());

		//The old tokens keep working:
		self::assertSame(200, $this->request('GET', '/me', 'token-alice')->status);
		self::assertSame(array('person' => 'Alice', 'teams' => array('default')), $this->request('GET', '/me', 'token-alice')->data);
	}

	/**
	 * @return void
	 */
	public function testUpgradeMapsPrivateRuimteToPrivateTeam(): void
	{
		$this->createDutchDatabase(true, true);
		$this->migrator()->migrate();

		self::assertSame(
			array(array('alice-1', 'private'), array('bob-1', 'default')),
			$this->pdo->query('SELECT name, team FROM session ORDER BY name')->fetchAll(PDO::FETCH_NUM)
		);
		self::assertSame(
			array('private', 'default', 'private'),
			$this->pdo->query('SELECT team FROM message ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
		);
	}

	/**
	 * An older Dutch build may have other constraint names: that must never abort the upgrade.
	 * @return void
	 */
	public function testUpgradeSurvivesUnexpectedConstraintNames(): void
	{
		$this->createDutchDatabase(true, false);
		$this->pdo->exec('ALTER TABLE migratie DROP CONSTRAINT migratie_pkey');
		$this->pdo->exec('ALTER TABLE persoon RENAME CONSTRAINT persoon_naam_key TO odd_name_key');
		$this->pdo->exec('ALTER TABLE sessie RENAME CONSTRAINT sessie_persoon_id_fkey TO odd_fkey');
		$this->pdo->exec('ALTER TABLE bericht RENAME CONSTRAINT bericht_check TO odd_check');
		$this->pdo->exec('ALTER TABLE bericht_gelezen RENAME CONSTRAINT bericht_gelezen_pkey TO odd_pkey');
		$this->pdo->exec('ALTER INDEX sessie_laatst_gezien RENAME TO odd_index');

		self::assertSame(array('002_english_names.sql', '003_teams.sql', '004_oauth.sql'), $this->migrator()->migrate());
		self::assertSame(array('person' => 'Alice', 'teams' => array('default')), $this->request('GET', '/me', 'token-alice')->data);
	}

	/**
	 * @return void
	 */
	public function testUpgradeOfEmptyDutchDatabaseCreatesNoTeam(): void
	{
		$this->createDutchDatabase(false, false);
		$this->migrator()->migrate();

		self::assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM team')->fetchColumn());
	}

	/**
	 * @return Migrator
	 */
	private function migrator(): Migrator
	{
		return new Migrator($this->pdo, __DIR__ . '/../migrations');
	}

	/**
	 * Builds the database exactly as the old Dutch relay left it.
	 * @param bool $withData
	 * @param bool $withRuimte Also add the column of the unreleased Dutch "ruimte" work.
	 * @return void
	 */
	private function createDutchDatabase(bool $withData, bool $withRuimte): void
	{
		$this->pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public');
		$this->pdo->exec('CREATE TABLE IF NOT EXISTS migratie (naam text PRIMARY KEY, uitgevoerd timestamptz NOT NULL DEFAULT now())');
		$this->pdo->exec((string) file_get_contents(__DIR__ . '/../migrations/001_schema.sql'));
		$this->pdo->exec("INSERT INTO migratie (naam) VALUES ('001_schema.sql')");
		if($withRuimte === true) {
			$this->pdo->exec("ALTER TABLE sessie ADD COLUMN ruimte text NOT NULL DEFAULT 'team' CHECK (ruimte IN ('team', 'prive'))");
			$this->pdo->exec("INSERT INTO migratie (naam) VALUES ('002_ruimte.sql')");
		}
		if($withData === false) {
			return;
		}
		$this->pdo->exec("INSERT INTO persoon (naam, token_hash) VALUES ('Alice', '" . hash('sha256', 'token-alice') . "'), ('Bob', '" . hash('sha256', 'token-bob') . "')");
		$this->pdo->exec("INSERT INTO sessie (naam, persoon_id, machine, repo, repo_basis) SELECT lower(naam) || '-1', id, 'pc', 'app', 'app' FROM persoon");
		if($withRuimte === true) {
			$this->pdo->exec("UPDATE sessie SET ruimte = 'prive' WHERE naam = 'alice-1'");
		}
		$this->pdo->exec(
			"INSERT INTO bericht (van_sessie, van_persoon_id, aan_sessie, soort, tekst) VALUES
				('alice-1', (SELECT id FROM persoon WHERE naam = 'Alice'), 'alice-1', 'melding', 'hi'),
				('bob-1', (SELECT id FROM persoon WHERE naam = 'Bob'), 'bob-1', 'vraag', 'why?')"
		);
		$this->pdo->exec("INSERT INTO bericht (van_sessie, van_persoon_id, aan_sessie, soort, tekst, antwoord_op) SELECT 'alice-1', persoon.id, 'bob-1', 'antwoord', 'because', bericht.id FROM persoon, bericht WHERE persoon.naam = 'Alice' AND bericht.soort = 'vraag'");
		$this->pdo->exec("INSERT INTO bericht_gelezen (bericht_id, sessie) SELECT id, 'alice-1' FROM bericht WHERE soort = 'melding'");
	}

	/**
	 * @return list<string> Every table, column, constraint, index, sequence and function name.
	 */
	private function schemaNames(): array
	{
		return $this->pdo->query(
			"SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'
			 UNION SELECT column_name FROM information_schema.columns WHERE table_schema = 'public'
			 UNION SELECT conname FROM pg_constraint WHERE connamespace = 'public'::regnamespace
			 UNION SELECT relname FROM pg_class WHERE relnamespace = 'public'::regnamespace
			 UNION SELECT proname FROM pg_proc WHERE pronamespace = 'public'::regnamespace
			 ORDER BY 1"
		)->fetchAll(PDO::FETCH_COLUMN);
	}

	/**
	 * @return array<string, list<string>> A comparable description of the whole schema (column order ignored).
	 */
	private function schemaDump(): array
	{
		$query = fn(string $sql): array => $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

		return array(
			'columns' => $query(
				"SELECT table_name || '.' || column_name || ' ' || data_type || ' null=' || is_nullable || ' default=' || coalesce(column_default, '-')
				 FROM information_schema.columns WHERE table_schema = 'public' ORDER BY 1"
			),
			'constraints' => $query(
				"SELECT conrelid::regclass || ' ' || conname || ' ' || pg_get_constraintdef(oid)
				 FROM pg_constraint WHERE connamespace = 'public'::regnamespace ORDER BY 1"
			),
			'indexes' => $query("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' ORDER BY 1"),
			'relations' => $query("SELECT relname || ' ' || relkind::text FROM pg_class WHERE relnamespace = 'public'::regnamespace ORDER BY 1"),
			'functions' => $query(
				"SELECT proname || '(' || pg_get_function_arguments(oid) || ') ' || md5(prosrc)
				 FROM pg_proc WHERE pronamespace = 'public'::regnamespace ORDER BY 1"
			),
		);
	}
}
