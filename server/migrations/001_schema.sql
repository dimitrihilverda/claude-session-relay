CREATE TABLE persoon (
	id serial PRIMARY KEY,
	naam text NOT NULL UNIQUE,
	token_hash text NOT NULL UNIQUE,
	actief boolean NOT NULL DEFAULT true
);

CREATE TABLE sessie (
	naam text PRIMARY KEY,
	persoon_id integer NOT NULL REFERENCES persoon(id),
	machine text NOT NULL,
	repo text NOT NULL,
	repo_basis text NOT NULL,
	branch text NOT NULL DEFAULT '',
	ticket text,
	claim jsonb NOT NULL DEFAULT '[]',
	sinds timestamptz NOT NULL DEFAULT now(),
	laatst_gezien timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX sessie_laatst_gezien ON sessie (laatst_gezien);

CREATE TABLE bericht (
	id bigserial PRIMARY KEY,
	van_sessie text NOT NULL,
	van_persoon_id integer NOT NULL REFERENCES persoon(id),
	aan_sessie text,
	aan_persoon_id integer REFERENCES persoon(id),
	soort text NOT NULL CHECK (soort IN ('melding', 'vraag', 'antwoord')),
	tekst text NOT NULL CHECK (char_length(tekst) BETWEEN 1 AND 4000),
	antwoord_op bigint REFERENCES bericht(id) ON DELETE SET NULL,
	aangemaakt timestamptz NOT NULL DEFAULT now(),
	CHECK ((aan_sessie IS NULL) <> (aan_persoon_id IS NULL))
);
CREATE INDEX bericht_aan_sessie ON bericht (aan_sessie);
CREATE INDEX bericht_aan_persoon ON bericht (aan_persoon_id, aangemaakt);

CREATE TABLE bericht_gelezen (
	bericht_id bigint NOT NULL REFERENCES bericht(id) ON DELETE CASCADE,
	sessie text NOT NULL,
	gelezen timestamptz NOT NULL DEFAULT now(),
	PRIMARY KEY (bericht_id, sessie)
);
