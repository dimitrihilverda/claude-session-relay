-- Teams: a session belongs to a team its person is a member of, or to 'private'.

CREATE TABLE team (
	id serial PRIMARY KEY,
	name text NOT NULL UNIQUE CHECK (name ~ '^[a-z0-9][a-z0-9._-]*$' AND char_length(name) <= 40 AND name <> 'private'),
	created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE team_member (
	team_id integer NOT NULL REFERENCES team(id) ON DELETE CASCADE,
	person_id integer NOT NULL REFERENCES person(id) ON DELETE CASCADE,
	PRIMARY KEY (team_id, person_id)
);
CREATE INDEX team_member_person ON team_member (person_id);

ALTER TABLE session ADD COLUMN team text;
ALTER TABLE message ADD COLUMN team text;

-- Existing data: every person joins team 'default' (rename it with `relay team:rename`).
INSERT INTO team (name) SELECT 'default' WHERE EXISTS (SELECT 1 FROM person);
INSERT INTO team_member (team_id, person_id) SELECT t.id, p.id FROM team t CROSS JOIN person p WHERE t.name = 'default';

-- An older Dutch relay may have a 'ruimte' column ('team' or 'prive'): private stays private.
DO $$
BEGIN
	IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'session' AND column_name = 'ruimte') THEN
		EXECUTE $sql$UPDATE message m SET team = 'private' FROM session s WHERE s.name = m.from_session AND s.person_id = m.from_person_id AND s.ruimte = 'prive'$sql$;
		EXECUTE $sql$UPDATE session SET team = 'private' WHERE ruimte = 'prive'$sql$;
		EXECUTE 'ALTER TABLE session DROP COLUMN ruimte';
	END IF;
END $$;

UPDATE session SET team = 'default' WHERE team IS NULL;
UPDATE message SET team = 'default' WHERE team IS NULL;
ALTER TABLE session ALTER COLUMN team SET NOT NULL;
ALTER TABLE message ALTER COLUMN team SET NOT NULL;

-- THE visibility rule, the only place it is written down. Something (a session, a message)
-- in team `item_team` owned by `owner_id` is visible to `viewer_id` iff:
--   item_team = 'private' and the viewer is the owner, or
--   item_team <> 'private' and the viewer is a member of item_team.
CREATE FUNCTION visible_to(item_team text, owner_id integer, viewer_id integer) RETURNS boolean
	LANGUAGE sql STABLE
AS $$
	SELECT CASE
		WHEN item_team = 'private' THEN owner_id = viewer_id
		ELSE EXISTS (
			SELECT 1 FROM team_member m JOIN team t ON t.id = m.team_id
			WHERE t.name = item_team AND m.person_id = viewer_id
		)
	END
$$;
