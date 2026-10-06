-- English names for the original schema (001_schema.sql). Data is kept as it is,
-- except the message kinds, which are translated.
-- Tables and columns must exist; constraint, index and sequence names are renamed only when
-- they exist, so an older build with other names never aborts the upgrade.

-- Tables and columns
ALTER TABLE persoon RENAME TO person;
ALTER TABLE person RENAME COLUMN naam TO name;
ALTER TABLE person RENAME COLUMN actief TO active;

ALTER TABLE sessie RENAME TO session;
ALTER TABLE session RENAME COLUMN naam TO name;
ALTER TABLE session RENAME COLUMN persoon_id TO person_id;
ALTER TABLE session RENAME COLUMN repo_basis TO repo_base;
ALTER TABLE session RENAME COLUMN sinds TO started_at;
ALTER TABLE session RENAME COLUMN laatst_gezien TO last_seen;

ALTER TABLE bericht RENAME TO message;
ALTER TABLE message RENAME COLUMN van_sessie TO from_session;
ALTER TABLE message RENAME COLUMN van_persoon_id TO from_person_id;
ALTER TABLE message RENAME COLUMN aan_sessie TO to_session;
ALTER TABLE message RENAME COLUMN aan_persoon_id TO to_person_id;
ALTER TABLE message RENAME COLUMN soort TO kind;
ALTER TABLE message RENAME COLUMN tekst TO text;
ALTER TABLE message RENAME COLUMN antwoord_op TO reply_to;
ALTER TABLE message RENAME COLUMN aangemaakt TO created_at;

ALTER TABLE bericht_gelezen RENAME TO message_read;
ALTER TABLE message_read RENAME COLUMN bericht_id TO message_id;
ALTER TABLE message_read RENAME COLUMN sessie TO session;
ALTER TABLE message_read RENAME COLUMN gelezen TO read_at;

-- Sequences and indexes
ALTER SEQUENCE IF EXISTS persoon_id_seq RENAME TO person_id_seq;
ALTER SEQUENCE IF EXISTS bericht_id_seq RENAME TO message_id_seq;
ALTER INDEX IF EXISTS sessie_laatst_gezien RENAME TO session_last_seen;
ALTER INDEX IF EXISTS bericht_aan_sessie RENAME TO message_to_session;
ALTER INDEX IF EXISTS bericht_aan_persoon RENAME TO message_to_person;

-- Message kinds: melding -> note, vraag -> question, antwoord -> answer
DO $$
DECLARE
	c record;
BEGIN
	--The old check on the Dutch kinds, whatever it is called:
	FOR c IN SELECT conname FROM pg_constraint WHERE conrelid = to_regclass('message') AND contype = 'c' AND pg_get_constraintdef(oid) LIKE '%melding%' LOOP
		EXECUTE format('ALTER TABLE message DROP CONSTRAINT %I', c.conname);
	END LOOP;
END $$;
UPDATE message SET kind = CASE kind WHEN 'melding' THEN 'note' WHEN 'vraag' THEN 'question' WHEN 'antwoord' THEN 'answer' ELSE kind END;
ALTER TABLE message ADD CONSTRAINT message_kind_check CHECK (kind IN ('note', 'question', 'answer'));

-- Constraints (each only when it exists under the expected name)
DO $$
DECLARE
	r text[];
BEGIN
	FOREACH r SLICE 1 IN ARRAY ARRAY[
		['person', 'persoon_pkey', 'person_pkey'],
		['person', 'persoon_naam_key', 'person_name_key'],
		['person', 'persoon_token_hash_key', 'person_token_hash_key'],
		['session', 'sessie_pkey', 'session_pkey'],
		['session', 'sessie_persoon_id_fkey', 'session_person_id_fkey'],
		['message', 'bericht_pkey', 'message_pkey'],
		['message', 'bericht_van_persoon_id_fkey', 'message_from_person_id_fkey'],
		['message', 'bericht_aan_persoon_id_fkey', 'message_to_person_id_fkey'],
		['message', 'bericht_antwoord_op_fkey', 'message_reply_to_fkey'],
		['message', 'bericht_tekst_check', 'message_text_check'],
		['message', 'bericht_check', 'message_recipient_check'],
		['message_read', 'bericht_gelezen_pkey', 'message_read_pkey'],
		['message_read', 'bericht_gelezen_bericht_id_fkey', 'message_read_message_id_fkey']
	]
	LOOP
		IF EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = to_regclass(r[1]) AND conname = r[2])
			AND NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = to_regclass(r[1]) AND conname = r[3]) THEN
			EXECUTE format('ALTER TABLE %I RENAME CONSTRAINT %I TO %I', r[1], r[2], r[3]);
		END IF;
	END LOOP;
END $$;

-- PostgreSQL 18+ names its NOT NULL constraints after the old table and column; rename them too.
DO $$
DECLARE
	c record;
BEGIN
	FOR c IN
		SELECT con.conname, rel.relname, att.attname
		FROM pg_constraint con
		JOIN pg_class rel ON rel.oid = con.conrelid
		JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = con.conkey[1]
		WHERE con.contype = 'n' AND rel.relnamespace = current_schema()::regnamespace
			AND rel.relname IN ('person', 'session', 'message', 'message_read')
	LOOP
		IF c.conname <> c.relname || '_' || c.attname || '_not_null'
			AND NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = to_regclass(c.relname) AND conname = c.relname || '_' || c.attname || '_not_null') THEN
			EXECUTE format('ALTER TABLE %I RENAME CONSTRAINT %I TO %I', c.relname, c.conname, c.relname || '_' || c.attname || '_not_null');
		END IF;
	END LOOP;
END $$;
