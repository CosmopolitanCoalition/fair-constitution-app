--
-- PostgreSQL database dump
--

-- Dumped from database version 17.5 (Debian 17.5-1.pgdg110+1)
-- Dumped by pg_dump version 17.5 (Debian 17.5-1.pgdg110+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: tiger; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA tiger;


--
-- Name: tiger_data; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA tiger_data;


--
-- Name: topology; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA topology;


--
-- Name: SCHEMA topology; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA topology IS 'PostGIS Topology schema';


--
-- Name: fuzzystrmatch; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS fuzzystrmatch WITH SCHEMA public;


--
-- Name: EXTENSION fuzzystrmatch; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION fuzzystrmatch IS 'determine similarities and distance between strings';


--
-- Name: postgis; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS postgis WITH SCHEMA public;


--
-- Name: EXTENSION postgis; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION postgis IS 'PostGIS geometry and geography spatial types and functions';


--
-- Name: postgis_raster; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS postgis_raster WITH SCHEMA public;


--
-- Name: EXTENSION postgis_raster; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION postgis_raster IS 'PostGIS raster types and functions';


--
-- Name: postgis_tiger_geocoder; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS postgis_tiger_geocoder WITH SCHEMA tiger;


--
-- Name: EXTENSION postgis_tiger_geocoder; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION postgis_tiger_geocoder IS 'PostGIS tiger geocoder and reverse geocoder';


--
-- Name: postgis_topology; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS postgis_topology WITH SCHEMA topology;


--
-- Name: EXTENSION postgis_topology; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION postgis_topology IS 'PostGIS topology spatial types and functions';


--
-- Name: achievements_block_mutation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.achievements_block_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RAISE EXCEPTION 'achievements is append-only: % is not permitted', TG_OP;
            END;
            $$;


--
-- Name: audit_checkpoints_block_mutation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.audit_checkpoints_block_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RAISE EXCEPTION 'audit_checkpoints is append-only: % is not permitted', TG_OP;
            END;
            $$;


--
-- Name: audit_log_block_mutation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.audit_log_block_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RAISE EXCEPTION 'audit_log is append-only: % is not permitted', TG_OP;
            END;
            $$;


--
-- Name: case_filings_block_mutation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.case_filings_block_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RAISE EXCEPTION 'case_filings is an append-only docket: % is not permitted (nothing argued in open court is sealed retroactively)', TG_OP;
            END;
            $$;


--
-- Name: cgc_ip_register_block_mutation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.cgc_ip_register_block_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RAISE EXCEPTION 'cgc_ip_register is append-only and irreversible (Art. III §5): % is not permitted', TG_OP;
            END;
            $$;


--
-- Name: population_within(character varying, public.geometry, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.population_within(p_iso_code character varying, p_geom public.geometry, p_year smallint DEFAULT 2023) RETURNS bigint
    LANGUAGE sql STABLE
    AS $$
        WITH s AS MATERIALIZED (
            -- Conditional simplification for the giants + ST_MakeValid
            -- for everyone. ST_MakeValid is idempotent on already-
            -- valid input (cheap) and resolves GEOS precision issues
            -- (the "side location conflict at <lat,lng>" failures
            -- previously seen on JPN, NOR, RWA, etc.) on borderline
            -- valid input.
            SELECT ST_MakeValid(
                CASE
                    WHEN ST_NPoints(p_geom) > 50000
                    THEN ST_SimplifyPreserveTopology(p_geom, 0.001)
                    ELSE p_geom
                END
            ) AS geom
        )
        SELECT COALESCE(
            ROUND(
                SUM((ST_SummaryStats(ST_Clip(r.rast, s.geom, TRUE))).sum)
            )::BIGINT,
            0
        )
        FROM  worldpop_rasters r CROSS JOIN s
        WHERE r.iso_code = p_iso_code
          AND r.year     = p_year
          -- Use s.geom (prepared) here too — same bbox as the native
          -- polygon at 0.001° simplification tolerance, but doesn't
          -- inherit the GEOS precision conflicts that the native
          -- polygon does.
          AND ST_Intersects(r.rast, s.geom);
    $$;


--
-- Name: population_within_multi(public.geometry, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.population_within_multi(p_geom public.geometry, p_year smallint DEFAULT 2023) RETURNS bigint
    LANGUAGE sql STABLE
    AS $$
        WITH s AS MATERIALIZED (
            -- Mirror population_within(): ST_MakeValid for everyone (idempotent
            -- on valid input), conditional simplify for million-vertex giants.
            SELECT ST_MakeValid(
                CASE
                    WHEN ST_NPoints(p_geom) > 50000
                    THEN ST_SimplifyPreserveTopology(p_geom, 0.001)
                    ELSE p_geom
                END
            ) AS geom
        ),
        clipped AS (
            SELECT ST_Clip(r.rast, s.geom, TRUE) AS rast
            FROM   worldpop_rasters r CROSS JOIN s
            WHERE  r.year = p_year
              AND  ST_Intersects(r.rast, s.geom)
        )
        SELECT COALESCE(
            ROUND(
                -- MAX-per-pixel union de-dups border-overlap pixels (two
                -- country rasters covering the same cell), then sum.
                (ST_SummaryStats(ST_Union(rast, 'MAX'))).sum
            )::BIGINT,
            0
        )
        FROM clipped;
    $$;


--
-- Name: public_records_block_mutation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.public_records_block_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RAISE EXCEPTION 'public_records is append-only: % is not permitted', TG_OP;
            END;
            $$;


--
-- Name: set_location_ping_geom(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.set_location_ping_geom() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
            BEGIN
                NEW.geom = ST_SetSRID(ST_MakePoint(NEW.longitude, NEW.latitude), 4326);
                RETURN NEW;
            END;
            $$;


--
-- Name: sync_log_block_mutation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.sync_log_block_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RAISE EXCEPTION 'sync_log is append-only: % is not permitted', TG_OP;
            END;
            $$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: achievements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.achievements (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    user_id uuid NOT NULL,
    journey_id character varying(64) NOT NULL,
    title character varying(255) NOT NULL,
    source_server_id uuid,
    audit_seq bigint,
    earned_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone
);


--
-- Name: actor_devices; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.actor_devices (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    user_id uuid NOT NULL,
    device_public_key text NOT NULL,
    label character varying(255),
    enrolled_at timestamp(0) with time zone NOT NULL,
    revoked_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone
);


--
-- Name: admin_offices; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_offices (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    legislature_id uuid NOT NULL,
    created_by_vote_id uuid,
    created_by_law_id uuid,
    status character varying(12) DEFAULT 'created'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT admin_offices_status_check CHECK (((status)::text = ANY ((ARRAY['created'::character varying, 'staffed'::character varying, 'dissolved'::character varying])::text[])))
);


--
-- Name: advocates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.advocates (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    user_id uuid NOT NULL,
    judiciary_id uuid NOT NULL,
    jurisdiction_id uuid NOT NULL,
    status character varying(12) DEFAULT 'registered'::character varying NOT NULL,
    qualifications_note text,
    registered_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT advocates_status_check CHECK (((status)::text = ANY ((ARRAY['registered'::character varying, 'suspended'::character varying, 'withdrawn'::character varying])::text[])))
);


--
-- Name: appointments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.appointments (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    appointable_type character varying(64) NOT NULL,
    appointable_id uuid NOT NULL,
    nominee_user_id uuid NOT NULL,
    nominated_by uuid,
    nominated_via_form character varying(16),
    consent_vote_id uuid,
    status character varying(12) DEFAULT 'nominated'::character varying NOT NULL,
    term_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT appointments_status_check CHECK (((status)::text = ANY ((ARRAY['nominated'::character varying, 'consented'::character varying, 'rejected'::character varying, 'seated'::character varying, 'ended'::character varying])::text[])))
);


--
-- Name: appropriations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.appropriations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    law_id uuid NOT NULL,
    jurisdiction_id uuid NOT NULL,
    executive_id uuid NOT NULL,
    line character varying(255) NOT NULL,
    amount numeric(18,2) NOT NULL,
    remaining numeric(18,2) NOT NULL,
    status character varying(12) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT appropriations_remaining_check CHECK (((remaining >= (0)::numeric) AND (remaining <= amount))),
    CONSTRAINT appropriations_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'exhausted'::character varying, 'lapsed'::character varying])::text[])))
);


--
-- Name: approval_standings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.approval_standings (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    race_id uuid NOT NULL,
    candidacy_id uuid NOT NULL,
    as_of_date date NOT NULL,
    approvals_count integer NOT NULL,
    rank smallint NOT NULL,
    delta integer DEFAULT 0 NOT NULL,
    is_frozen boolean DEFAULT false NOT NULL,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: approvals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.approvals (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    election_id uuid NOT NULL,
    candidacy_id uuid NOT NULL,
    user_id uuid NOT NULL,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    revoked_at timestamp(0) with time zone
);


--
-- Name: attestation_revocations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.attestation_revocations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    attestation_id uuid NOT NULL,
    issuer_server_id uuid NOT NULL,
    reason character varying(48),
    revoked_at timestamp(0) with time zone NOT NULL,
    signature text NOT NULL,
    source_server_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone
);


--
-- Name: audit_chain_reconciliations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_chain_reconciliations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    break_seq bigint NOT NULL,
    observed_prev_hash character(64) NOT NULL,
    expected_prev_hash character(64) NOT NULL,
    reason text NOT NULL,
    authority_kind character varying(24) NOT NULL,
    acknowledged_by_user_id uuid,
    acknowledged_by_operator_id uuid,
    consent jsonb,
    audit_seq bigint,
    acknowledged_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT audit_chain_reconciliations_authority_check CHECK (((authority_kind)::text = ANY ((ARRAY['government_office'::character varying, 'operator_collective'::character varying])::text[])))
);


--
-- Name: audit_checkpoints; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_checkpoints (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    audit_seq bigint NOT NULL,
    head_hash character(64) NOT NULL,
    published_to jsonb DEFAULT '[]'::jsonb NOT NULL,
    signature text NOT NULL,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    seq bigint NOT NULL
);


--
-- Name: audit_checkpoints_seq_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.audit_checkpoints_seq_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audit_checkpoints_seq_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.audit_checkpoints_seq_seq OWNED BY public.audit_checkpoints.seq;


--
-- Name: audit_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_log (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    occurred_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    actor_user_id uuid,
    module character varying(32) NOT NULL,
    event character varying(64) NOT NULL,
    ref character varying(24),
    jurisdiction_id uuid,
    payload jsonb DEFAULT '{}'::jsonb NOT NULL,
    prev_hash character(64) NOT NULL,
    hash character(64) NOT NULL,
    rejected boolean DEFAULT false NOT NULL,
    blocked_reason text,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    seq bigint NOT NULL
);


--
-- Name: audit_log_seq_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.audit_log_seq_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audit_log_seq_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.audit_log_seq_seq OWNED BY public.audit_log.seq;


--
-- Name: authority_claims; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.authority_claims (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    claimed_by_peer_id uuid,
    resolution character varying(16) DEFAULT 'uncontested'::character varying NOT NULL,
    authority_flipped_at timestamp(0) with time zone,
    partition_export_id uuid,
    notes text,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT authority_claims_resolution_check CHECK (((resolution)::text = ANY ((ARRAY['uncontested'::character varying, 'recognized'::character varying, 'negotiating'::character varying, 'mirrored'::character varying])::text[])))
);


--
-- Name: ballot_envelopes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ballot_envelopes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    race_id uuid,
    user_id uuid NOT NULL,
    kind character varying(12) NOT NULL,
    referendum_question_id uuid,
    committed_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT ballot_envelopes_kind_check CHECK (((kind)::text = ANY ((ARRAY['ranked'::character varying, 'referendum'::character varying])::text[]))),
    CONSTRAINT ballot_envelopes_kind_pairing_check CHECK (((((kind)::text = 'ranked'::text) AND (race_id IS NOT NULL) AND (referendum_question_id IS NULL)) OR (((kind)::text = 'referendum'::text) AND (referendum_question_id IS NOT NULL) AND (race_id IS NULL))))
);


--
-- Name: ballots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ballots (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    race_id uuid,
    kind character varying(12) NOT NULL,
    payload_encrypted text NOT NULL,
    salt character(64) NOT NULL,
    ballot_hash character(64) NOT NULL,
    cast_bucket timestamp(0) with time zone NOT NULL,
    counted boolean DEFAULT false NOT NULL,
    referendum_question_id uuid,
    CONSTRAINT ballots_kind_check CHECK (((kind)::text = ANY ((ARRAY['ranked'::character varying, 'referendum'::character varying])::text[]))),
    CONSTRAINT ballots_kind_pairing_check CHECK (((((kind)::text = 'ranked'::text) AND (race_id IS NOT NULL) AND (referendum_question_id IS NULL)) OR (((kind)::text = 'referendum'::text) AND (referendum_question_id IS NOT NULL) AND (race_id IS NULL))))
);


--
-- Name: bill_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.bill_versions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    bill_id uuid NOT NULL,
    version_no smallint NOT NULL,
    law_text text NOT NULL,
    changed_by_member_id uuid,
    change_kind character varying(24) NOT NULL,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT bill_versions_change_kind_check CHECK (((change_kind)::text = ANY ((ARRAY['introduction'::character varying, 'committee_amendment'::character varying, 'floor_amendment'::character varying])::text[])))
);


--
-- Name: bills; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.bills (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    legislature_id uuid NOT NULL,
    jurisdiction_id uuid NOT NULL,
    sponsor_member_id uuid NOT NULL,
    title character varying(255) NOT NULL,
    act_type character varying(20) NOT NULL,
    scale jsonb NOT NULL,
    scope_judiciary_id uuid,
    targets_setting_key character varying(255),
    proposed_value jsonb,
    effective_at timestamp(0) with time zone,
    status character varying(16) DEFAULT 'introduced'::character varying NOT NULL,
    committee_id uuid,
    current_version_no smallint DEFAULT '1'::smallint NOT NULL,
    introduced_at timestamp(0) with time zone,
    passed_at timestamp(0) with time zone,
    failed_at timestamp(0) with time zone,
    enacted_at timestamp(0) with time zone,
    enacted_law_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    targets_challenge_id uuid,
    CONSTRAINT bills_act_type_check CHECK (((act_type)::text = ANY ((ARRAY['ordinary'::character varying, 'setting_change'::character varying, 'supermajority'::character varying, 'dual_supermajority'::character varying])::text[]))),
    CONSTRAINT bills_setting_pairing_check CHECK ((((act_type)::text = 'setting_change'::text) = (targets_setting_key IS NOT NULL))),
    CONSTRAINT bills_status_check CHECK (((status)::text = ANY ((ARRAY['introduced'::character varying, 'referred'::character varying, 'in_committee'::character varying, 'reported'::character varying, 'tabled'::character varying, 'on_floor'::character varying, 'passed'::character varying, 'failed'::character varying, 'enacted'::character varying, 'withdrawn'::character varying])::text[])))
);


--
-- Name: board_seats; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.board_seats (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    board_id uuid NOT NULL,
    seat_class character varying(16) NOT NULL,
    seat_no smallint NOT NULL,
    holder_user_id uuid,
    appointment_id uuid,
    elected_in_race_id uuid,
    term_id uuid,
    is_chair boolean DEFAULT false NOT NULL,
    status character varying(20) DEFAULT 'vacant'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT board_seats_seat_class_check CHECK (((seat_class)::text = ANY ((ARRAY['governor'::character varying, 'owner_elected'::character varying, 'worker_elected'::character varying])::text[]))),
    CONSTRAINT board_seats_status_check CHECK (((status)::text = ANY ((ARRAY['vacant'::character varying, 'nominated'::character varying, 'seated'::character varying, 'removal_requested'::character varying, 'removed'::character varying, 'term_ended'::character varying])::text[])))
);


--
-- Name: boards; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.boards (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    boardable_type character varying(32) NOT NULL,
    boardable_id uuid NOT NULL,
    owner_seats smallint NOT NULL,
    worker_seats smallint DEFAULT '0'::smallint NOT NULL,
    worker_headcount integer DEFAULT 0 NOT NULL,
    chair_seat_id uuid,
    composition_valid boolean DEFAULT true NOT NULL,
    cycle_months smallint DEFAULT '60'::smallint NOT NULL,
    status character varying(12) DEFAULT 'forming'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT boards_boardable_type_check CHECK (((boardable_type)::text = ANY ((ARRAY['departments'::character varying, 'organizations'::character varying])::text[]))),
    CONSTRAINT boards_owner_seats_check CHECK ((owner_seats >= 1)),
    CONSTRAINT boards_status_check CHECK (((status)::text = ANY ((ARRAY['forming'::character varying, 'active'::character varying, 'dissolved'::character varying])::text[]))),
    CONSTRAINT boards_worker_seats_check CHECK ((worker_seats >= 0))
);


--
-- Name: border_settlements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.border_settlements (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_a_id uuid NOT NULL,
    jurisdiction_b_id uuid NOT NULL,
    affected_jurisdiction_ids jsonb DEFAULT '[]'::jsonb NOT NULL,
    affected_population integer DEFAULT 0 NOT NULL,
    referendum_election_id uuid,
    affected_supermajority_met boolean DEFAULT false NOT NULL,
    jurisdiction_map_id uuid,
    status character varying(16) DEFAULT 'open'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT border_settlements_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'adopted'::character varying, 'rejected'::character varying, 'expired'::character varying])::text[])))
);


--
-- Name: broker_authorizations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.broker_authorizations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    domain character varying(253) NOT NULL,
    broker_server_id uuid NOT NULL,
    authority_server_id uuid NOT NULL,
    authority_pubkey text NOT NULL,
    signature text NOT NULL,
    issued_at timestamp(0) with time zone NOT NULL,
    revoked_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone
);


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: candidacies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.candidacies (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    election_id uuid NOT NULL,
    race_id uuid,
    user_id uuid NOT NULL,
    status character varying(16) DEFAULT 'registered'::character varying NOT NULL,
    platform_statement text,
    position_tags jsonb DEFAULT '[]'::jsonb NOT NULL,
    residency_attested_at timestamp(0) with time zone NOT NULL,
    validated_at timestamp(0) with time zone,
    validated_by_member_id uuid,
    rejection_reason character varying(32),
    withdrawn_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT candidacies_rejection_reason_check CHECK (((rejection_reason IS NULL) OR ((rejection_reason)::text = 'no_residency_association'::text))),
    CONSTRAINT candidacies_status_check CHECK (((status)::text = ANY ((ARRAY['registered'::character varying, 'validated'::character varying, 'rejected'::character varying, 'in_pool'::character varying, 'finalist'::character varying, 'non_finalist'::character varying, 'withdrawn'::character varying, 'elected'::character varying, 'defeated'::character varying])::text[])))
);


--
-- Name: case_filings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_filings (
    seq bigint NOT NULL,
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    case_id uuid NOT NULL,
    filing_form character varying(16) NOT NULL,
    filing_kind character varying(16) NOT NULL,
    filed_by_user_id uuid,
    filed_by_role character varying(8),
    advocate_id uuid,
    title text,
    body text,
    ruling character varying(12),
    ruling_reason text,
    accepted_at_state character varying(20),
    record_id uuid,
    audit_seq bigint,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT case_filings_filing_form_check CHECK (((filing_form)::text = ANY ((ARRAY['F-IND-017'::character varying, 'F-ADV-001'::character varying, 'F-ADV-002'::character varying, 'F-ADV-003'::character varying, 'F-ADV-004'::character varying, 'F-JDG-001'::character varying, 'F-JDG-002'::character varying, 'F-JDG-003'::character varying, 'F-JDG-009'::character varying, 'F-JDG-010'::character varying])::text[]))),
    CONSTRAINT case_filings_filing_kind_check CHECK (((filing_kind)::text = ANY ((ARRAY['case_filing'::character varying, 'motion'::character varying, 'evidence'::character varying, 'brief'::character varying, 'order'::character varying, 'panel_assignment'::character varying, 'jury_order'::character varying, 'opinion'::character varying, 'sentence'::character varying, 'warrant'::character varying, 'ruling'::character varying])::text[]))),
    CONSTRAINT case_filings_ruling_check CHECK (((ruling IS NULL) OR ((ruling)::text = ANY ((ARRAY['granted'::character varying, 'denied'::character varying, 'admitted'::character varying, 'excluded'::character varying])::text[]))))
);


--
-- Name: case_filings_seq_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.case_filings ALTER COLUMN seq ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.case_filings_seq_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: case_parties; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_parties (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    case_id uuid NOT NULL,
    party_role character varying(16) NOT NULL,
    party_type character varying(16) NOT NULL,
    party_user_id uuid,
    party_ref_type character varying(32),
    party_ref_id uuid,
    represented_by_advocate_id uuid,
    retainer_note text,
    status character varying(12) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT case_parties_party_role_check CHECK (((party_role)::text = ANY ((ARRAY['prosecution'::character varying, 'plaintiff'::character varying, 'defendant'::character varying, 'respondent'::character varying, 'intervenor'::character varying, 'accused'::character varying])::text[]))),
    CONSTRAINT case_parties_party_type_check CHECK (((party_type)::text = ANY ((ARRAY['individual'::character varying, 'organization'::character varying, 'jurisdiction'::character varying, 'government_body'::character varying])::text[]))),
    CONSTRAINT case_parties_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'withdrawn'::character varying, 'substituted'::character varying])::text[])))
);


--
-- Name: cases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cases (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    docket_no character varying(24) NOT NULL,
    judiciary_id uuid NOT NULL,
    jurisdiction_id uuid NOT NULL,
    kind character varying(16) NOT NULL,
    title character varying(255) NOT NULL,
    statement_of_claim text,
    claimed_severity character varying(12),
    court_severity character varying(20),
    jury_entitled boolean DEFAULT false NOT NULL,
    jury_waived boolean DEFAULT false NOT NULL,
    filed_via_form character varying(16) NOT NULL,
    filed_by_user_id uuid,
    filed_on_behalf_of_user_id uuid,
    advocate_id uuid,
    panel_id uuid,
    jury_id uuid,
    appeal_of_case_id uuid,
    status character varying(20) NOT NULL,
    double_jeopardy_locked boolean DEFAULT false NOT NULL,
    accepted_at timestamp(0) with time zone,
    decided_at timestamp(0) with time zone,
    closed_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT cases_claimed_severity_check CHECK (((claimed_severity IS NULL) OR ((claimed_severity)::text = ANY ((ARRAY['minor'::character varying, 'moderate'::character varying, 'serious'::character varying])::text[])))),
    CONSTRAINT cases_court_severity_check CHECK (((court_severity IS NULL) OR ((court_severity)::text = ANY ((ARRAY['minor'::character varying, 'moderate'::character varying, 'serious'::character varying, 'constitutional_major'::character varying])::text[])))),
    CONSTRAINT cases_filed_via_form_check CHECK (((filed_via_form)::text = ANY ((ARRAY['F-IND-017'::character varying, 'F-ADV-001'::character varying, 'F-IND-016'::character varying])::text[]))),
    CONSTRAINT cases_kind_check CHECK (((kind)::text = ANY ((ARRAY['civil'::character varying, 'criminal'::character varying, 'administrative'::character varying, 'constitutional'::character varying])::text[]))),
    CONSTRAINT cases_status_check CHECK (((status)::text = ANY ((ARRAY['filed'::character varying, 'accepted'::character varying, 'paneled'::character varying, 'jury_empaneled'::character varying, 'heard'::character varying, 'deliberation'::character varying, 'decided'::character varying, 'sentenced'::character varying, 'closed'::character varying, 'dismissed'::character varying, 'appealed'::character varying])::text[])))
);


--
-- Name: cgc_ip_register; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cgc_ip_register (
    seq bigint NOT NULL,
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    asset character varying(255) NOT NULL,
    kind character varying(24) NOT NULL,
    description text,
    status character varying(13) DEFAULT 'public_domain'::character varying NOT NULL,
    dedicated_via_form character varying(12) NOT NULL,
    dedicated_by_user_id uuid,
    published_record_id uuid,
    audit_seq bigint,
    published_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT cgc_ip_register_kind_check CHECK (((kind)::text = ANY ((ARRAY['software'::character varying, 'patentable_invention'::character varying, 'copyrightable_work'::character varying, 'design'::character varying, 'data'::character varying, 'process'::character varying, 'other'::character varying])::text[]))),
    CONSTRAINT cgc_ip_register_status_public_domain CHECK (((status)::text = 'public_domain'::text))
);


--
-- Name: cgc_ip_register_seq_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.cgc_ip_register ALTER COLUMN seq ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.cgc_ip_register_seq_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: chamber_vote_proposals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.chamber_vote_proposals (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    legislature_id uuid NOT NULL,
    proposal_kind character varying(32) NOT NULL,
    vote_id uuid,
    payload jsonb DEFAULT '{}'::jsonb NOT NULL,
    proposed_by_member_id uuid,
    status character varying(12) DEFAULT 'open'::character varying NOT NULL,
    decided_at timestamp(0) with time zone,
    result_type character varying(40),
    result_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT chamber_vote_proposals_kind_check CHECK (((proposal_kind)::text = ANY ((ARRAY['committee_creation'::character varying, 'election_board_creation'::character varying, 'admin_office_creation'::character varying, 'rules_of_order'::character varying, 'ethics_code'::character varying, 'referendum_delegation'::character varying, 'referendum_act_modification'::character varying, 'emergency_invocation'::character varying, 'emergency_renewal'::character varying, 'exec_delegation'::character varying, 'exec_conversion'::character varying, 'department_creation'::character varying, 'cgc_creation'::character varying, 'monopoly_acquisition'::character varying, 'cgc_reorg_sale'::character varying, 'judiciary_creation'::character varying, 'judiciary_conversion'::character varying, 'judiciary_dissolution'::character varying, 'judiciary_override'::character varying, 'cultural_institution'::character varying, 'union'::character varying, 'disintermediation'::character varying, 'local_autonomy_promotion'::character varying])::text[]))),
    CONSTRAINT chamber_vote_proposals_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'adopted'::character varying, 'rejected'::character varying])::text[])))
);


--
-- Name: chamber_vote_tallies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.chamber_vote_tallies (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    vote_id uuid NOT NULL,
    lane character varying(8) NOT NULL,
    serving smallint NOT NULL,
    quorum_required smallint NOT NULL,
    required_yes smallint NOT NULL,
    present smallint,
    yes smallint DEFAULT '0'::smallint NOT NULL,
    no smallint DEFAULT '0'::smallint NOT NULL,
    abstain smallint DEFAULT '0'::smallint NOT NULL,
    quorate boolean,
    passed boolean,
    CONSTRAINT chamber_vote_tallies_counts_check CHECK ((((yes + no) + abstain) <= serving)),
    CONSTRAINT chamber_vote_tallies_lane_check CHECK (((lane)::text = ANY ((ARRAY['all'::character varying, 'type_a'::character varying, 'type_b'::character varying])::text[])))
);


--
-- Name: chamber_votes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.chamber_votes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    body_type character varying(16) NOT NULL,
    body_id uuid NOT NULL,
    legislature_id uuid,
    jurisdiction_id uuid NOT NULL,
    votable_type character varying(32),
    votable_id uuid,
    vote_type character varying(40) NOT NULL,
    vote_method character varying(8) NOT NULL,
    threshold_basis character varying(16) NOT NULL,
    stage character varying(12),
    bicameral boolean DEFAULT false NOT NULL,
    serving_snapshot smallint NOT NULL,
    held_in_session_id uuid,
    opened_by_member_id uuid,
    opened_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    closes_at timestamp(0) with time zone,
    decided_at timestamp(0) with time zone,
    outcome character varying(12),
    speaker_tiebreak boolean DEFAULT false NOT NULL,
    rcv_record jsonb,
    status character varying(8) DEFAULT 'open'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT chamber_votes_body_type_check CHECK (((body_type)::text = ANY ((ARRAY['legislature'::character varying, 'committee'::character varying, 'board'::character varying])::text[]))),
    CONSTRAINT chamber_votes_outcome_check CHECK (((outcome IS NULL) OR ((outcome)::text = ANY ((ARRAY['adopted'::character varying, 'failed'::character varying, 'tied'::character varying])::text[])))),
    CONSTRAINT chamber_votes_stage_check CHECK (((stage IS NULL) OR ((stage)::text = ANY ((ARRAY['committee'::character varying, 'floor'::character varying])::text[])))),
    CONSTRAINT chamber_votes_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'closed'::character varying, 'void'::character varying])::text[]))),
    CONSTRAINT chamber_votes_threshold_basis_check CHECK (((threshold_basis)::text = ANY ((ARRAY['majority'::character varying, 'supermajority'::character varying])::text[]))),
    CONSTRAINT chamber_votes_vote_method_check CHECK (((vote_method)::text = ANY ((ARRAY['yes_no'::character varying, 'rcv'::character varying])::text[])))
);


--
-- Name: clock_timers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.clock_timers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    clock_id character varying(8) NOT NULL,
    jurisdiction_id uuid,
    subject_type character varying(64),
    subject_id uuid,
    armed_at timestamp(0) with time zone NOT NULL,
    fires_at timestamp(0) with time zone,
    state character varying(12) DEFAULT 'armed'::character varying NOT NULL,
    payload jsonb DEFAULT '{}'::jsonb NOT NULL,
    override_value jsonb,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT clock_timers_state_check CHECK (((state)::text = ANY ((ARRAY['armed'::character varying, 'fired'::character varying, 'cancelled'::character varying, 'expired'::character varying])::text[])))
);


--
-- Name: clocks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.clocks (
    id character varying(8) NOT NULL,
    name character varying(64) NOT NULL,
    type character varying(12) NOT NULL,
    default_value jsonb DEFAULT '{}'::jsonb NOT NULL,
    amendable boolean DEFAULT false NOT NULL,
    fires_workflow character varying(64),
    basis text,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT clocks_type_check CHECK (((type)::text = ANY ((ARRAY['recurring'::character varying, 'countdown'::character varying, 'window'::character varying, 'threshold'::character varying, 'derived'::character varying, 'flag'::character varying])::text[])))
);


--
-- Name: cluster_adoption_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cluster_adoption_requests (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    applicant_server_id uuid NOT NULL,
    applicant_public_key text NOT NULL,
    nonce character varying(64) NOT NULL,
    admission_method character varying(12) DEFAULT 'join_key'::character varying NOT NULL,
    status character varying(12) DEFAULT 'pending'::character varying NOT NULL,
    join_key_handle character varying(16),
    cluster_membership_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    requested_relation character varying(16),
    requested_scope_jurisdiction_id uuid,
    applicant_name character varying(255),
    applicant_url character varying(255),
    note text,
    CONSTRAINT cluster_adoption_requests_method_check CHECK (((admission_method)::text = ANY ((ARRAY['join_key'::character varying, 'request'::character varying])::text[]))),
    CONSTRAINT cluster_adoption_requests_relation_check CHECK (((requested_relation IS NULL) OR ((requested_relation)::text = ANY ((ARRAY['mirror'::character varying, 'co_member'::character varying])::text[])))),
    CONSTRAINT cluster_adoption_requests_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'admitted'::character varying, 'rejected'::character varying, 'expired'::character varying])::text[])))
);


--
-- Name: cluster_join_keys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cluster_join_keys (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    handle character varying(16) NOT NULL,
    key_hash text NOT NULL,
    max_uses integer DEFAULT 1 NOT NULL,
    uses integer DEFAULT 0 NOT NULL,
    scope_jurisdiction_id uuid,
    expires_at timestamp(0) with time zone,
    revoked_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT cluster_join_keys_max_uses_check CHECK ((max_uses >= 1)),
    CONSTRAINT cluster_join_keys_uses_check CHECK ((uses >= 0))
);


--
-- Name: cluster_members; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cluster_members (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    cluster_id uuid NOT NULL,
    server_id uuid NOT NULL,
    is_self boolean DEFAULT false NOT NULL,
    state character varying(12) DEFAULT 'forming'::character varying NOT NULL,
    role character varying(16) DEFAULT 'co_member'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT cluster_members_state_check CHECK (((state)::text = ANY ((ARRAY['forming'::character varying, 'admitted'::character varying, 'live'::character varying, 'suspended'::character varying, 'departed'::character varying])::text[])))
);


--
-- Name: cluster_memberships; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cluster_memberships (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    peer_id uuid NOT NULL,
    role character varying(8) NOT NULL,
    state character varying(12) DEFAULT 'requested'::character varying NOT NULL,
    admission_method character varying(12),
    scope_jurisdiction_id uuid,
    backfill_cursor_seq bigint,
    backfill_target_seq bigint,
    backfilled_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    seed_dataset character varying(255),
    seed_version character varying(255),
    seed_sha256 character varying(255),
    seed_total_bytes bigint,
    seed_cursor_bytes bigint DEFAULT '0'::bigint NOT NULL,
    seeded_at timestamp(0) with time zone,
    CONSTRAINT cluster_memberships_admission_check CHECK (((admission_method)::text = ANY ((ARRAY['join_key'::character varying, 'request'::character varying])::text[]))),
    CONSTRAINT cluster_memberships_role_check CHECK (((role)::text = ANY ((ARRAY['mirror'::character varying, 'host'::character varying])::text[]))),
    CONSTRAINT cluster_memberships_state_check CHECK (((state)::text = ANY ((ARRAY['requested'::character varying, 'admitted'::character varying, 'syncing'::character varying, 'live'::character varying, 'suspended'::character varying, 'departed'::character varying, 'rejected'::character varying])::text[])))
);


--
-- Name: clusters; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.clusters (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    name character varying(255),
    kind character varying(12) DEFAULT 'authority'::character varying NOT NULL,
    jurisdiction_id uuid,
    authority_claim_id uuid,
    is_self boolean DEFAULT false NOT NULL,
    leader_server_id uuid,
    leader_epoch bigint DEFAULT '0'::bigint NOT NULL,
    topology character varying(16),
    dcs_backend character varying(16),
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT clusters_kind_check CHECK (((kind)::text = ANY ((ARRAY['authority'::character varying, 'mirror'::character varying])::text[])))
);


--
-- Name: committee_meetings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.committee_meetings (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    committee_id uuid NOT NULL,
    called_by_member_id uuid NOT NULL,
    scheduled_for timestamp(0) with time zone NOT NULL,
    agenda jsonb DEFAULT '[]'::jsonb NOT NULL,
    opened_at timestamp(0) with time zone,
    adjourned_at timestamp(0) with time zone,
    status character varying(12) DEFAULT 'scheduled'::character varying NOT NULL,
    minutes_record_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT committee_meetings_status_check CHECK (((status)::text = ANY ((ARRAY['scheduled'::character varying, 'open'::character varying, 'adjourned'::character varying])::text[])))
);


--
-- Name: committee_preferences; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.committee_preferences (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    legislature_id uuid NOT NULL,
    member_id uuid NOT NULL,
    rankings jsonb DEFAULT '[]'::jsonb NOT NULL,
    submitted_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone
);


--
-- Name: committee_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.committee_reports (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    committee_id uuid NOT NULL,
    bill_id uuid,
    filed_by_member_id uuid NOT NULL,
    report_record_id uuid NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone
);


--
-- Name: committee_seats; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.committee_seats (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    committee_id uuid NOT NULL,
    member_id uuid NOT NULL,
    seat_kind character varying(8),
    status character varying(12) DEFAULT 'assigned'::character varying NOT NULL,
    assigned_via character varying(16),
    preference_rank_honored smallint,
    seated_at timestamp(0) with time zone,
    vacated_at timestamp(0) with time zone,
    vacated_reason character varying(24),
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT committee_seats_assigned_via_check CHECK (((assigned_via IS NULL) OR ((assigned_via)::text = ANY ((ARRAY['algorithm'::character varying, 'tie_break'::character varying, 'whole_house_rcv'::character varying])::text[])))),
    CONSTRAINT committee_seats_kind_check CHECK (((seat_kind IS NULL) OR ((seat_kind)::text = ANY ((ARRAY['type_a'::character varying, 'type_b'::character varying])::text[])))),
    CONSTRAINT committee_seats_status_check CHECK (((status)::text = ANY ((ARRAY['allocated'::character varying, 'assigned'::character varying, 'tie_broken'::character varying, 'seated'::character varying, 'vacated'::character varying])::text[])))
);


--
-- Name: committees; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.committees (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    legislature_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    purpose text,
    seats smallint NOT NULL,
    type_a_seats smallint,
    type_b_seats smallint,
    created_by_vote_id uuid,
    created_by_law_id uuid,
    chair_member_id uuid,
    alternate_member_id uuid,
    status character varying(12) DEFAULT 'created'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT committees_kind_split_check CHECK ((((type_a_seats IS NULL) AND (type_b_seats IS NULL)) OR ((type_a_seats IS NOT NULL) AND (type_b_seats IS NOT NULL) AND ((type_a_seats + type_b_seats) = seats)))),
    CONSTRAINT committees_seats_check CHECK ((seats >= 1)),
    CONSTRAINT committees_status_check CHECK (((status)::text = ANY ((ARRAY['created'::character varying, 'seated'::character varying, 'dissolved'::character varying])::text[])))
);


--
-- Name: constituent_consents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.constituent_consents (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    process_id uuid NOT NULL,
    jurisdiction_id uuid NOT NULL,
    legislature_id uuid,
    chamber_vote_id uuid,
    result character varying(8) DEFAULT 'pending'::character varying NOT NULL,
    decided_at timestamp(0) with time zone,
    CONSTRAINT constituent_consents_result_check CHECK (((result)::text = ANY ((ARRAY['pending'::character varying, 'yes'::character varying, 'no'::character varying])::text[])))
);


--
-- Name: constitutional_challenges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.constitutional_challenges (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    judiciary_id uuid NOT NULL,
    challenged_law_id uuid NOT NULL,
    challenged_version_no smallint NOT NULL,
    filed_by_user_id uuid NOT NULL,
    claim_text text NOT NULL,
    claimed_basis character varying(20) NOT NULL,
    cited_authority_law_id uuid,
    constitutional_citation character varying(64),
    case_id uuid,
    status character varying(28) NOT NULL,
    finding_id uuid,
    remedy_id uuid,
    resolution_path character varying(24),
    resolution_ref_type character varying(40),
    resolution_ref_id uuid,
    filed_at timestamp(0) with time zone,
    heard_at timestamp(0) with time zone,
    finding_at timestamp(0) with time zone,
    closed_at timestamp(0) with time zone,
    record_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT constitutional_challenges_claimed_basis_check CHECK (((claimed_basis)::text = ANY ((ARRAY['constitution'::character varying, 'other_law'::character varying])::text[]))),
    CONSTRAINT constitutional_challenges_resolution_path_check CHECK (((resolution_path IS NULL) OR ((resolution_path)::text = ANY ((ARRAY['legislative_amendment'::character varying, 'legislature_override'::character varying, 'judicial_remedy'::character varying, 'dismissed'::character varying])::text[])))),
    CONSTRAINT constitutional_challenges_status_check CHECK (((status)::text = ANY ((ARRAY['filed'::character varying, 'under_review'::character varying, 'dismissed'::character varying, 'finding_issued'::character varying, 'remedy_recommended'::character varying, 'legislative_window_open'::character varying, 'amended_by_legislature'::character varying, 'overridden'::character varying, 'judicial_remedy_applied'::character varying, 'closed'::character varying])::text[])))
);


--
-- Name: constitutional_findings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.constitutional_findings (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    challenge_id uuid NOT NULL,
    judiciary_id uuid NOT NULL,
    case_id uuid,
    full_court boolean DEFAULT false NOT NULL,
    finds_contradiction boolean NOT NULL,
    contradiction_against character varying(20) NOT NULL,
    superior_authority_law_id uuid,
    constitutional_citation character varying(64),
    offending_law_id uuid NOT NULL,
    offending_version_no smallint NOT NULL,
    opinion_text text NOT NULL,
    panel_snapshot jsonb DEFAULT '[]'::jsonb NOT NULL,
    record_id uuid,
    issued_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT constitutional_findings_against_check CHECK (((contradiction_against)::text = ANY ((ARRAY['constitution'::character varying, 'other_law'::character varying])::text[])))
);


--
-- Name: constitutional_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.constitutional_settings (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    election_interval_months smallint DEFAULT '60'::smallint NOT NULL,
    voting_method character varying(255) DEFAULT 'stv_droop'::character varying NOT NULL,
    special_election_min_days smallint DEFAULT '90'::smallint NOT NULL,
    special_election_max_days smallint DEFAULT '180'::smallint NOT NULL,
    legislature_min_seats smallint DEFAULT '5'::smallint NOT NULL,
    legislature_max_seats smallint DEFAULT '9'::smallint NOT NULL,
    supermajority_numerator smallint DEFAULT '2'::smallint NOT NULL,
    supermajority_denominator smallint DEFAULT '3'::smallint NOT NULL,
    max_days_between_meetings smallint DEFAULT '90'::smallint NOT NULL,
    emergency_powers_max_days smallint DEFAULT '90'::smallint NOT NULL,
    civil_appointment_years smallint DEFAULT '10'::smallint NOT NULL,
    judicial_appointment_years smallint DEFAULT '10'::smallint NOT NULL,
    judiciary_min_judges_per_race smallint DEFAULT '5'::smallint NOT NULL,
    judiciary_is_elected boolean DEFAULT false NOT NULL,
    worker_rep_min_employees smallint DEFAULT '100'::smallint NOT NULL,
    worker_rep_parity_employees smallint DEFAULT '2000'::smallint NOT NULL,
    residency_confirmation_days smallint DEFAULT '30'::smallint NOT NULL,
    initiative_petition_threshold_pct numeric(5,2) DEFAULT '5'::numeric NOT NULL,
    last_amended_by_act_id uuid,
    last_amended_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    type_b_seats_per_child smallint DEFAULT '5'::smallint NOT NULL,
    legislature_sizing_law character varying(255) DEFAULT 'cube_root'::character varying NOT NULL,
    critical_population_threshold integer,
    finalist_multiplier smallint DEFAULT '3'::smallint NOT NULL,
    ranked_window_days smallint DEFAULT '14'::smallint NOT NULL,
    approval_min_days smallint DEFAULT '30'::smallint NOT NULL,
    last_amendment_route character varying(24),
    last_amendment_process_id uuid,
    CONSTRAINT constitutional_settings_amendment_route_check CHECK (((last_amendment_route IS NULL) OR ((last_amendment_route)::text = ANY ((ARRAY['legislative_supermajority'::character varying, 'constituent_supermajority'::character varying, 'population_supermajority'::character varying])::text[]))))
);


--
-- Name: COLUMN constitutional_settings.voting_method; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.constitutional_settings.voting_method IS 'stv_droop is constitutionally required default. Never replace with FPTP or plurality.';


--
-- Name: COLUMN constitutional_settings.legislature_max_seats; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.constitutional_settings.legislature_max_seats IS 'Max before mandatory subdivision. Constitution caps this at 9.';


--
-- Name: COLUMN constitutional_settings.worker_rep_min_employees; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.constitutional_settings.worker_rep_min_employees IS 'Minimum employees before worker-elected rep required';


--
-- Name: COLUMN constitutional_settings.worker_rep_parity_employees; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.constitutional_settings.worker_rep_parity_employees IS 'Employee count at which worker:shareholder seats are equal';


--
-- Name: COLUMN constitutional_settings.initiative_petition_threshold_pct; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.constitutional_settings.initiative_petition_threshold_pct IS 'Percentage of jurisdiction population required for citizen initiative';


--
-- Name: COLUMN constitutional_settings.last_amended_by_act_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.constitutional_settings.last_amended_by_act_id IS 'FK to legislative_acts table (created later)';


--
-- Name: COLUMN constitutional_settings.legislature_sizing_law; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.constitutional_settings.legislature_sizing_law IS 'Determines how total legislature size is derived from population. v1 ships with cube_root only; future: square_root|fixed_total|log_linear.';


--
-- Name: COLUMN constitutional_settings.critical_population_threshold; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.constitutional_settings.critical_population_threshold IS 'CLK-06 activation threshold (verified residents). NULL = inherit ancestor, then code default.';


--
-- Name: COLUMN constitutional_settings.finalist_multiplier; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.constitutional_settings.finalist_multiplier IS 'CLK-21: finalist count X = multiplier × seats, frozen per race at creation';


--
-- Name: COLUMN constitutional_settings.ranked_window_days; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.constitutional_settings.ranked_window_days IS 'Length of the ranked-voting window (days)';


--
-- Name: COLUMN constitutional_settings.approval_min_days; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.constitutional_settings.approval_min_days IS 'Minimum approval-phase length before a finalist cutoff may be set (days)';


--
-- Name: cosmic_addresses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cosmic_addresses (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    parent_id uuid,
    label character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    type character varying(255) NOT NULL,
    subtype character varying(255),
    enabled boolean DEFAULT false NOT NULL,
    source character varying(255) DEFAULT 'seed'::character varying NOT NULL,
    sort_order smallint DEFAULT '0'::smallint NOT NULL,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: COLUMN cosmic_addresses.type; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.cosmic_addresses.type IS 'multiverse|observable_universe|supercluster|galaxy_group|galaxy|galactic_region|star_system|world';


--
-- Name: COLUMN cosmic_addresses.subtype; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.cosmic_addresses.subtype IS 'For world: planet|moon|planetoid|asteroid|space_station|artificial_habitat';


--
-- Name: cultural_institutions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cultural_institutions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    legislature_id uuid,
    name character varying(200) NOT NULL,
    description text,
    recognition_vote_id uuid,
    status character varying(16) DEFAULT 'recognized'::character varying NOT NULL,
    record_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT cultural_institutions_status_check CHECK (((status)::text = ANY ((ARRAY['recognized'::character varying, 'dissolved'::character varying])::text[])))
);


--
-- Name: data_review_decisions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.data_review_decisions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    category character varying(64) NOT NULL,
    jurisdiction_id uuid,
    decision character varying(128) NOT NULL,
    note text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: department_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.department_reports (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    department_id uuid NOT NULL,
    kind character varying(8) DEFAULT 'periodic'::character varying NOT NULL,
    period_label character varying(255),
    due_on date NOT NULL,
    filed_at timestamp(0) with time zone,
    filed_by_seat_id uuid,
    recipients jsonb DEFAULT '["executive", "legislature"]'::jsonb NOT NULL,
    record_id uuid,
    status character varying(8) DEFAULT 'due'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT department_reports_kind_check CHECK (((kind)::text = ANY ((ARRAY['periodic'::character varying, 'special'::character varying])::text[]))),
    CONSTRAINT department_reports_status_check CHECK (((status)::text = ANY ((ARRAY['due'::character varying, 'filed'::character varying, 'overdue'::character varying])::text[])))
);


--
-- Name: department_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.department_rules (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    department_id uuid NOT NULL,
    rule_code character varying(32) NOT NULL,
    name character varying(255) NOT NULL,
    text text NOT NULL,
    enabling_type character varying(20) NOT NULL,
    enabling_id uuid NOT NULL,
    expires_with_enabling boolean DEFAULT false NOT NULL,
    version_no smallint DEFAULT '1'::smallint NOT NULL,
    supersedes_rule_id uuid,
    filed_by_seat_id uuid NOT NULL,
    record_id uuid,
    status character varying(12) DEFAULT 'in_force'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT department_rules_enabling_type_check CHECK (((enabling_type)::text = ANY ((ARRAY['law'::character varying, 'emergency_power'::character varying, 'charter'::character varying])::text[]))),
    CONSTRAINT department_rules_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'in_force'::character varying, 'superseded'::character varying, 'expired'::character varying])::text[])))
);


--
-- Name: departments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.departments (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    executive_id uuid NOT NULL,
    kind character varying(20) NOT NULL,
    name character varying(255) NOT NULL,
    charter_law_id uuid NOT NULL,
    reporting_interval_months smallint,
    board_id uuid,
    worker_count integer DEFAULT 0 NOT NULL,
    status character varying(24) DEFAULT 'chartered'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT departments_kind_check CHECK (((kind)::text = ANY ((ARRAY['chief_executive'::character varying, 'treasury'::character varying, 'defense'::character varying, 'state'::character varying, 'justice'::character varying, 'other'::character varying])::text[]))),
    CONSTRAINT departments_status_check CHECK (((status)::text = ANY ((ARRAY['chartered'::character varying, 'oversight_assigned'::character varying, 'governors_nominated'::character varying, 'consented'::character varying, 'operating'::character varying, 'reporting'::character varying, 'rechartered'::character varying, 'dissolved'::character varying])::text[])))
);


--
-- Name: directory_entries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.directory_entries (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    server_id uuid NOT NULL,
    endpoints jsonb NOT NULL,
    priority integer DEFAULT 100 NOT NULL,
    signature text,
    source_server_id uuid,
    published_at timestamp(0) with time zone,
    expires_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone
);


--
-- Name: disintermediation_processes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.disintermediation_processes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    intermediary_jurisdiction_id uuid NOT NULL,
    encompassing_jurisdiction_id uuid NOT NULL,
    constituent_process_id uuid,
    encompassing_consent boolean DEFAULT false NOT NULL,
    encompassing_consent_vote_id uuid,
    status character varying(16) DEFAULT 'open'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT disintermediation_processes_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'passed'::character varying, 'failed'::character varying, 'merged'::character varying, 'expired'::character varying])::text[])))
);


--
-- Name: district_subdivisions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.district_subdivisions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    map_id uuid NOT NULL,
    parent_jurisdiction_id uuid NOT NULL,
    parent_subdivision_id uuid,
    method character varying(20) NOT NULL,
    label character varying(120) NOT NULL,
    population bigint,
    population_source character varying(16) DEFAULT 'worldpop_raster'::character varying NOT NULL,
    population_year smallint,
    fractional_seats numeric(10,6),
    seats smallint,
    status character varying(16) DEFAULT 'draft'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    geom public.geometry(MultiPolygon,4326),
    centroid public.geometry(Point,4326),
    CONSTRAINT district_subdivisions_method_check CHECK (((method)::text = ANY ((ARRAY['splitline'::character varying, 'manual'::character varying, 'composite_synthetic'::character varying])::text[]))),
    CONSTRAINT district_subdivisions_population_source_check CHECK (((population_source)::text = ANY ((ARRAY['worldpop_raster'::character varying, 'civic'::character varying, 'manual_override'::character varying])::text[]))),
    CONSTRAINT district_subdivisions_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying, 'archived'::character varying])::text[])))
);


--
-- Name: election_audits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.election_audits (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    election_id uuid NOT NULL,
    race_id uuid,
    cause text NOT NULL,
    ordered_by uuid,
    ordered_at timestamp(0) with time zone NOT NULL,
    tabulation_id uuid,
    outcome character varying(12),
    resolved_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT election_audits_outcome_check CHECK (((outcome IS NULL) OR ((outcome)::text = ANY ((ARRAY['reaffirmed'::character varying, 'corrected'::character varying])::text[]))))
);


--
-- Name: election_ballot_key_rewraps; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.election_ballot_key_rewraps (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    election_id uuid NOT NULL,
    jurisdiction_id uuid,
    from_cluster_id uuid,
    to_cluster_id uuid,
    prior_wrap_fingerprint character varying(128),
    new_wrap_fingerprint character varying(128) NOT NULL,
    races_verified integer DEFAULT 0 NOT NULL,
    count_record_digest character varying(128),
    verified_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone
);


--
-- Name: election_board_members; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.election_board_members (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    election_board_id uuid NOT NULL,
    user_id uuid,
    appointment_id uuid,
    status character varying(12) DEFAULT 'nominated'::character varying NOT NULL,
    term_starts_on date,
    term_ends_on date,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT election_board_members_status_check CHECK (((status)::text = ANY ((ARRAY['nominated'::character varying, 'seated'::character varying, 'removed'::character varying, 'term_ended'::character varying])::text[]))),
    CONSTRAINT election_board_members_system_seated_check CHECK (((user_id IS NOT NULL) OR ((status)::text = 'seated'::text)))
);


--
-- Name: election_boards; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.election_boards (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    legislature_id uuid,
    created_by_act_id uuid,
    is_bootstrap boolean DEFAULT false NOT NULL,
    status character varying(12) DEFAULT 'forming'::character varying NOT NULL,
    retired_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT election_boards_status_check CHECK (((status)::text = ANY ((ARRAY['forming'::character varying, 'active'::character varying, 'retired'::character varying])::text[])))
);


--
-- Name: election_certifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.election_certifications (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    election_id uuid NOT NULL,
    election_board_id uuid NOT NULL,
    certified_by_member_id uuid,
    certified_at timestamp(0) with time zone NOT NULL,
    count_record_hash character(64) NOT NULL,
    status character varying(24) DEFAULT 'certified'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT election_certifications_status_check CHECK (((status)::text = ANY ((ARRAY['certified'::character varying, 'superseded_by_audit'::character varying])::text[])))
);


--
-- Name: election_races; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.election_races (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    election_id uuid NOT NULL,
    district_id uuid,
    jurisdiction_id uuid NOT NULL,
    seat_kind character varying(16) NOT NULL,
    seats smallint NOT NULL,
    finalist_count smallint NOT NULL,
    electorate_type character varying(12) DEFAULT 'residents'::character varying NOT NULL,
    quota integer,
    total_valid_ballots integer,
    status character varying(16) DEFAULT 'scheduled'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT election_races_electorate_type_check CHECK (((electorate_type)::text = ANY ((ARRAY['residents'::character varying, 'owners'::character varying, 'workers'::character varying])::text[]))),
    CONSTRAINT election_races_seat_kind_check CHECK (((seat_kind)::text = ANY ((ARRAY['type_a'::character varying, 'type_b'::character varying, 'single'::character varying, 'exec_committee'::character varying, 'judicial_group'::character varying])::text[]))),
    CONSTRAINT election_races_seats_check CHECK (((((seat_kind)::text = ANY ((ARRAY['type_a'::character varying, 'type_b'::character varying])::text[])) AND ((seats >= 1) AND (seats <= 9))) OR (((seat_kind)::text = 'single'::text) AND (seats = 1)) OR (((seat_kind)::text = 'exec_committee'::text) AND (seats >= 5)) OR (((seat_kind)::text = 'judicial_group'::text) AND (seats >= 5)))),
    CONSTRAINT election_races_status_check CHECK (((status)::text = ANY ((ARRAY['scheduled'::character varying, 'approval_open'::character varying, 'finalist_cutoff'::character varying, 'ranked_open'::character varying, 'voting_closed'::character varying, 'tabulating'::character varying, 'certified'::character varying, 'audit_rerun'::character varying, 'final'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: elections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.elections (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    kind character varying(255) DEFAULT 'general'::character varying NOT NULL,
    voting_method character varying(255) DEFAULT 'stv_droop'::character varying NOT NULL,
    status character varying(255) DEFAULT 'scheduled'::character varying NOT NULL,
    trigger character varying(255) DEFAULT 'scheduled'::character varying NOT NULL,
    election_board_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    legislature_id uuid,
    district_map_id uuid,
    approval_opens_at timestamp(0) with time zone,
    finalist_cutoff_at timestamp(0) with time zone,
    ranked_opens_at timestamp(0) with time zone,
    ranked_closes_at timestamp(0) with time zone,
    certified_at timestamp(0) with time zone,
    prior_election_id uuid,
    triggered_by_timer_id uuid,
    vacancy_id uuid,
    ballot_key_wrapped text,
    executive_id uuid,
    board_id uuid,
    judiciary_id uuid,
    constitutional_version character varying(255),
    CONSTRAINT elections_kind_check CHECK (((kind)::text = ANY ((ARRAY['general'::character varying, 'special'::character varying, 'executive'::character varying, 'judicial'::character varying, 'referendum'::character varying, 'org_board_owner'::character varying, 'org_board_worker'::character varying, 'restoration'::character varying])::text[]))),
    CONSTRAINT elections_status_check CHECK (((status)::text = ANY ((ARRAY['scheduled'::character varying, 'approval_open'::character varying, 'finalist_cutoff'::character varying, 'ranked_open'::character varying, 'voting_closed'::character varying, 'tabulating'::character varying, 'certified'::character varying, 'audit_rerun'::character varying, 'final'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: emergency_power_renewals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.emergency_power_renewals (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    emergency_power_id uuid NOT NULL,
    vote_id uuid NOT NULL,
    extension_days smallint NOT NULL,
    previous_expires_at timestamp(0) with time zone NOT NULL,
    new_expires_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT emergency_power_renewals_extension_check CHECK (((extension_days >= 1) AND (extension_days <= 90)))
);


--
-- Name: emergency_power_reviews; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.emergency_power_reviews (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    emergency_power_id uuid NOT NULL,
    judiciary_id uuid NOT NULL,
    case_id uuid,
    challenge_id uuid,
    review_basis character varying(28) NOT NULL,
    outcome character varying(12) NOT NULL,
    narrowed_area_jurisdiction_id uuid,
    narrowed_methods jsonb,
    opinion_text text NOT NULL,
    record_id uuid,
    issued_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT emergency_power_reviews_basis_check CHECK (((review_basis)::text = ANY ((ARRAY['duration'::character varying, 'area'::character varying, 'methods'::character varying, 'civic_process_disruption'::character varying, 'cause'::character varying])::text[]))),
    CONSTRAINT emergency_power_reviews_outcome_check CHECK (((outcome)::text = ANY ((ARRAY['upheld'::character varying, 'narrowed'::character varying, 'struck'::character varying])::text[])))
);


--
-- Name: emergency_powers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.emergency_powers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    legislature_id uuid NOT NULL,
    jurisdiction_id uuid NOT NULL,
    cause character varying(20) NOT NULL,
    label character varying(255) NOT NULL,
    declared_duration_days smallint NOT NULL,
    area_jurisdiction_id uuid NOT NULL,
    methods text NOT NULL,
    invoke_vote_id uuid NOT NULL,
    status character varying(16) DEFAULT 'active'::character varying NOT NULL,
    starts_at timestamp(0) with time zone NOT NULL,
    expires_at timestamp(0) with time zone NOT NULL,
    judicial_review_case_id uuid,
    review_outcome character varying(12),
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT emergency_powers_cause_check CHECK (((cause)::text = ANY ((ARRAY['natural_disaster'::character varying, 'actual_invasion'::character varying])::text[]))),
    CONSTRAINT emergency_powers_duration_check CHECK (((declared_duration_days >= 1) AND (declared_duration_days <= 90))),
    CONSTRAINT emergency_powers_review_outcome_check CHECK (((review_outcome IS NULL) OR ((review_outcome)::text = ANY ((ARRAY['upheld'::character varying, 'narrowed'::character varying, 'struck'::character varying])::text[])))),
    CONSTRAINT emergency_powers_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'under_review'::character varying, 'renewed'::character varying, 'expired'::character varying, 'struck'::character varying, 'narrowed'::character varying])::text[])))
);


--
-- Name: endorsement_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.endorsement_requests (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    candidacy_id uuid NOT NULL,
    organization_id uuid NOT NULL,
    message text,
    status character varying(12) DEFAULT 'pending'::character varying NOT NULL,
    requested_at timestamp(0) with time zone NOT NULL,
    decided_at timestamp(0) with time zone,
    endorsement_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT endorsement_requests_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'granted'::character varying, 'declined'::character varying])::text[])))
);


--
-- Name: endorsements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.endorsements (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    election_id uuid NOT NULL,
    candidate_id uuid NOT NULL,
    endorser_type character varying(255) NOT NULL,
    endorser_id uuid NOT NULL,
    statement text,
    endorsed_at timestamp(0) without time zone NOT NULL,
    withdrawn_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_public boolean DEFAULT false NOT NULL
);


--
-- Name: COLUMN endorsements.endorser_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.endorsements.endorser_id IS 'UUID of organization or user';


--
-- Name: COLUMN endorsements.statement; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.endorsements.statement IS 'Public endorsement statement';


--
-- Name: COLUMN endorsements.withdrawn_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.endorsements.withdrawn_at IS 'Endorsements can be withdrawn before voting opens';


--
-- Name: executive_investigations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.executive_investigations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    executive_id uuid NOT NULL,
    department_id uuid,
    ordered_by_member_id uuid NOT NULL,
    scope text NOT NULL,
    records_access jsonb DEFAULT '[]'::jsonb NOT NULL,
    findings_record_id uuid,
    outcome character varying(24) DEFAULT 'open'::character varying NOT NULL,
    outcome_ref_type character varying(32),
    outcome_ref_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT executive_investigations_outcome_check CHECK (((outcome)::text = ANY ((ARRAY['open'::character varying, 'policy_proposal'::character varying, 'removal_request'::character varying, 'legislative_referral'::character varying, 'closed_no_finding'::character varying])::text[])))
);


--
-- Name: executive_members; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.executive_members (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    executive_id uuid NOT NULL,
    user_id uuid,
    role character varying(16) DEFAULT 'principal'::character varying NOT NULL,
    rank smallint DEFAULT '0'::smallint NOT NULL,
    joined_at date,
    left_at date,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    legislature_member_id uuid,
    elected_in_race_id uuid,
    term_id uuid,
    selection character varying(24) DEFAULT 'delegated_proportional'::character varying NOT NULL,
    status character varying(16) DEFAULT 'seated'::character varying NOT NULL,
    CONSTRAINT executive_members_rank_check CHECK (((rank >= 0) AND (rank <= 4))),
    CONSTRAINT executive_members_role_check CHECK (((role)::text = ANY ((ARRAY['principal'::character varying, 'advisor'::character varying])::text[]))),
    CONSTRAINT executive_members_selection_check CHECK (((selection)::text = ANY ((ARRAY['delegated_proportional'::character varying, 'elected_stv'::character varying, 'elected_rcv'::character varying, 'advisor_derivation'::character varying, 'succession'::character varying])::text[]))),
    CONSTRAINT executive_members_status_check CHECK (((status)::text = ANY ((ARRAY['seated'::character varying, 'left'::character varying, 'removed'::character varying, 'succeeded'::character varying, 'term_ended'::character varying])::text[])))
);


--
-- Name: executive_orders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.executive_orders (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    executive_id uuid NOT NULL,
    issued_by_member_id uuid NOT NULL,
    department_id uuid,
    order_no character varying(20),
    title character varying(255) NOT NULL,
    body text NOT NULL,
    enabling_type character varying(20) NOT NULL,
    enabling_id uuid NOT NULL,
    target_domain character varying(24) NOT NULL,
    status character varying(24) DEFAULT 'drafted'::character varying NOT NULL,
    rejection_citation character varying(255),
    rejection_reason text,
    record_id uuid,
    judicial_review_case_id uuid,
    issued_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT executive_orders_enabling_type_check CHECK (((enabling_type)::text = ANY ((ARRAY['law'::character varying, 'emergency_power'::character varying, 'charter'::character varying])::text[]))),
    CONSTRAINT executive_orders_rejection_citation_check CHECK ((((status)::text = 'rejected_pre_issuance'::text) = (rejection_citation IS NOT NULL))),
    CONSTRAINT executive_orders_status_check CHECK (((status)::text = ANY ((ARRAY['drafted'::character varying, 'scope_validated'::character varying, 'issued'::character varying, 'rejected_pre_issuance'::character varying, 'under_review'::character varying, 'struck'::character varying, 'revoked'::character varying])::text[]))),
    CONSTRAINT executive_orders_target_domain_check CHECK (((target_domain)::text = ANY ((ARRAY['department_operations'::character varying, 'public_works'::character varying, 'emergency_response'::character varying, 'administration'::character varying, 'other'::character varying, 'electoral_process'::character varying, 'judicial_process'::character varying, 'legislative_process'::character varying])::text[])))
);


--
-- Name: executives; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.executives (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    type character varying(16) DEFAULT 'committee'::character varying NOT NULL,
    term_number smallint DEFAULT '1'::smallint NOT NULL,
    term_starts_on date,
    term_ends_on date,
    status character varying(16) DEFAULT 'forming'::character varying NOT NULL,
    parent_executive_id uuid,
    source_legislature_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    delegation_law_id uuid,
    delegated_scope text,
    conversion_process_id uuid,
    conversion_law_id uuid,
    converted_at timestamp(0) with time zone,
    delegated_member_count smallint,
    CONSTRAINT executives_delegated_member_count_check CHECK (((delegated_member_count IS NULL) OR (delegated_member_count >= 5))),
    CONSTRAINT executives_status_check CHECK (((status)::text = ANY ((ARRAY['forming'::character varying, 'delegated'::character varying, 'conversion_voted'::character varying, 'elected'::character varying, 'dissolved'::character varying, 'reverted'::character varying])::text[]))),
    CONSTRAINT executives_type_check CHECK (((type)::text = ANY ((ARRAY['committee'::character varying, 'individual'::character varying])::text[])))
);


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: federation_peers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.federation_peers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    server_id uuid NOT NULL,
    name character varying(255),
    url character varying(255) NOT NULL,
    public_key text,
    status character varying(24) DEFAULT 'discovered'::character varying NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    last_heartbeat_at timestamp(0) with time zone,
    trust_established_at timestamp(0) with time zone,
    last_synced_seq bigint,
    peer_head_seq bigint,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    relation character varying(16) DEFAULT 'sovereign'::character varying NOT NULL,
    constitutional_version character varying(255),
    app_release character varying(255),
    CONSTRAINT federation_peers_relation_check CHECK (((relation)::text = ANY ((ARRAY['sovereign'::character varying, 'host'::character varying, 'mirror'::character varying])::text[]))),
    CONSTRAINT federation_peers_status_check CHECK (((status)::text = ANY ((ARRAY['discovered'::character varying, 'handshake'::character varying, 'trust_established'::character varying, 'syncing'::character varying, 'conflict_resolution'::character varying, 'border_settled'::character varying, 'merged'::character varying, 'departed'::character varying])::text[])))
);


--
-- Name: COLUMN federation_peers.relation; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.federation_peers.relation IS 'Phase G: the peer''s role to us — sovereign | host (a host we mirror) | mirror (a mirror of us)';


--
-- Name: federation_transport_health; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.federation_transport_health (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    server_id uuid NOT NULL,
    transport character varying(16) NOT NULL,
    url text NOT NULL,
    last_ok_at timestamp(0) with time zone,
    last_fail_at timestamp(0) with time zone,
    consecutive_failures integer DEFAULT 0 NOT NULL,
    latency_ema_ms integer,
    circuit_state character varying(12) DEFAULT 'closed'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT federation_transport_health_circuit_check CHECK (((circuit_state)::text = ANY ((ARRAY['closed'::character varying, 'open'::character varying, 'half_open'::character varying])::text[])))
);


--
-- Name: federation_transports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.federation_transports (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    server_id uuid NOT NULL,
    transport character varying(16) NOT NULL,
    address text NOT NULL,
    is_self boolean DEFAULT false NOT NULL,
    priority integer DEFAULT 100 NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT federation_transports_transport_check CHECK (((transport)::text = ANY ((ARRAY['https'::character varying, 'tailnet'::character varying, 'onion'::character varying, 'sneakernet'::character varying, 'yggdrasil'::character varying])::text[])))
);


--
-- Name: finding_offending_laws; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.finding_offending_laws (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    finding_id uuid NOT NULL,
    law_id uuid NOT NULL,
    version_no smallint NOT NULL,
    remedy_recommendation_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone
);


--
-- Name: forwarded_writes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.forwarded_writes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    origin_server_id uuid NOT NULL,
    idempotency_key character varying(128) NOT NULL,
    form_id character varying(64) NOT NULL,
    jurisdiction_id uuid,
    status character varying(12) DEFAULT 'pending'::character varying NOT NULL,
    audit_seq bigint,
    result_hash character varying(128),
    citation character varying(255),
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT forwarded_writes_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'executed'::character varying, 'rejected'::character varying])::text[])))
);


--
-- Name: foundation_sync_cursors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.foundation_sync_cursors (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    peer_id uuid NOT NULL,
    table_name character varying(255) NOT NULL,
    from_key json,
    next_from_key json,
    page_size integer DEFAULT 250 NOT NULL,
    pages_applied integer DEFAULT 0 NOT NULL,
    rows_applied bigint DEFAULT '0'::bigint NOT NULL,
    total_rows bigint,
    status character varying(255) DEFAULT 'open'::character varying NOT NULL,
    abort_reason character varying(255),
    detail json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: geoboundary_metadata; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.geoboundary_metadata (
    iso_code character(3) NOT NULL,
    adm_level smallint NOT NULL,
    boundary_id character varying(64),
    name character varying(255),
    year_represented smallint,
    boundary_type character varying(16),
    boundary_canonical text,
    boundary_source text,
    boundary_license text,
    license_detail text,
    license_source text,
    boundary_source_url text,
    source_data_update_date character varying(64),
    build_date character varying(64),
    continent character varying(64),
    unsdg_region character varying(128),
    unsdg_subregion character varying(128),
    world_bank_income_group character varying(64),
    adm_unit_count integer,
    mean_vertices double precision,
    min_vertices integer,
    max_vertices integer,
    mean_perimeter_length_km double precision,
    min_perimeter_length_km double precision,
    max_perimeter_length_km double precision,
    mean_area_sq_km double precision,
    min_area_sq_km double precision,
    max_area_sq_km double precision,
    static_download_link text,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone
);


--
-- Name: geodata_dataset_manifests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.geodata_dataset_manifests (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    dataset character varying(64) NOT NULL,
    version character varying(32) NOT NULL,
    sha256 character(64) NOT NULL,
    license character varying(255) NOT NULL,
    size_bytes bigint NOT NULL,
    origin_server_id uuid NOT NULL,
    signature text NOT NULL,
    fetched_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone
);


--
-- Name: governor_removal_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.governor_removal_requests (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    board_seat_id uuid NOT NULL,
    requested_by_member_id uuid NOT NULL,
    grounds text NOT NULL,
    record_id uuid,
    vote_id uuid,
    outcome character varying(12) DEFAULT 'pending'::character varying NOT NULL,
    decided_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT governor_removal_requests_outcome_check CHECK (((outcome)::text = ANY ((ARRAY['pending'::character varying, 'removed'::character varying, 'retained'::character varying])::text[])))
);


--
-- Name: grant_applications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.grant_applications (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    appropriation_id uuid NOT NULL,
    applicant_org_id uuid NOT NULL,
    amount numeric(18,2) NOT NULL,
    purpose text NOT NULL,
    status character varying(12) DEFAULT 'submitted'::character varying NOT NULL,
    decided_by_member_id uuid,
    decided_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT grant_applications_amount_check CHECK ((amount > (0)::numeric)),
    CONSTRAINT grant_applications_status_check CHECK (((status)::text = ANY ((ARRAY['submitted'::character varying, 'awarded'::character varying, 'declined'::character varying, 'withdrawn'::character varying])::text[])))
);


--
-- Name: grant_disbursements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.grant_disbursements (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    application_id uuid NOT NULL,
    amount numeric(18,2) NOT NULL,
    disbursed_by_member_id uuid NOT NULL,
    disbursed_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT grant_disbursements_amount_check CHECK ((amount > (0)::numeric))
);


--
-- Name: instance_capabilities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.instance_capabilities (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    server_id uuid NOT NULL,
    capability character varying(32) NOT NULL,
    is_self boolean DEFAULT false NOT NULL,
    enabled boolean DEFAULT false NOT NULL,
    priority integer DEFAULT 100 NOT NULL,
    granted_by_server_id uuid,
    grant_signature text,
    grant_expires_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT instance_capabilities_capability_check CHECK (((capability)::text = ANY ((ARRAY['mesh.member'::character varying, 'mirror'::character varying, 'etl'::character varying, 'broker.dns'::character varying, 'broker.tls'::character varying, 'client.serve'::character varying, 'authority.grant'::character varying, 'matrix.homeserver'::character varying, 'voice.sfu'::character varying])::text[])))
);


--
-- Name: instance_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.instance_settings (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    instance_name character varying(255) DEFAULT 'Unnamed Instance'::character varying NOT NULL,
    cosmic_address_id uuid,
    map_mode character varying(255) DEFAULT 'physical_earth'::character varying NOT NULL,
    time_mode character varying(255) DEFAULT 'real'::character varying NOT NULL,
    time_scale_seconds_per_year integer,
    setup_step_completed smallint DEFAULT '0'::smallint NOT NULL,
    setup_completed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    pending_constitutional_defaults jsonb,
    apportionment_completed_at timestamp with time zone,
    apportionment_log text,
    setup_districts_confirmed_at timestamp with time zone,
    setup_completion_notes jsonb,
    map_accepted_at timestamp with time zone,
    server_id uuid,
    public_key text,
    private_key_encrypted text,
    signing_key_generated_at timestamp(0) with time zone,
    federation_enabled boolean DEFAULT false NOT NULL,
    mirror_of_server_id uuid,
    mirror_adopted_at timestamp(0) with time zone,
    attestation_authority_enabled boolean DEFAULT false NOT NULL,
    home_cluster_id uuid,
    geodata_posture character varying(24),
    constitutional_version character varying(255),
    app_release character varying(255),
    version_pinned_at timestamp(0) with time zone,
    setup_mode character varying(255),
    infra_overrides jsonb,
    CONSTRAINT instance_settings_geodata_posture_check CHECK (((geodata_posture IS NULL) OR ((geodata_posture)::text = ANY ((ARRAY['already_have'::character varying, 'pull_from_origin'::character varying, 'skip'::character varying])::text[]))))
);


--
-- Name: COLUMN instance_settings.map_mode; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.instance_settings.map_mode IS 'physical_earth|multiverse|elsewhere|no_map';


--
-- Name: COLUMN instance_settings.time_mode; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.instance_settings.time_mode IS 'real|accelerated';


--
-- Name: COLUMN instance_settings.time_scale_seconds_per_year; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.instance_settings.time_scale_seconds_per_year IS 'Used when time_mode=accelerated; null in real mode';


--
-- Name: COLUMN instance_settings.server_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.instance_settings.server_id IS 'Phase F: stable federation identity; NULL until federation:init mints it';


--
-- Name: COLUMN instance_settings.public_key; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.instance_settings.public_key IS 'Ed25519 public key (base64), shared at handshake';


--
-- Name: COLUMN instance_settings.private_key_encrypted; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.instance_settings.private_key_encrypted IS 'Ed25519 secret key, Crypt-encrypted at rest; never exported';


--
-- Name: COLUMN instance_settings.federation_enabled; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.instance_settings.federation_enabled IS 'Operator gate for the /api/federation/* mesh endpoints';


--
-- Name: COLUMN instance_settings.mirror_of_server_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.instance_settings.mirror_of_server_id IS 'Phase G: set => this instance is a READ-ONLY mirror of the host with this server_id';


--
-- Name: COLUMN instance_settings.attestation_authority_enabled; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.instance_settings.attestation_authority_enabled IS 'Phase G G-ID: opt-in to issue standing attestations (ships dark)';


--
-- Name: COLUMN instance_settings.home_cluster_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.instance_settings.home_cluster_id IS 'Phase G G·co-member: the cluster this instance is a co-member of';


--
-- Name: invites; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invites (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    handle character varying(16) NOT NULL,
    token_hash text NOT NULL,
    inviter_user_id uuid,
    kind character varying(32) NOT NULL,
    destination jsonb NOT NULL,
    label character varying(160),
    max_uses integer,
    uses integer DEFAULT 0 NOT NULL,
    expires_at timestamp(0) with time zone,
    revoked_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT invites_max_uses_check CHECK (((max_uses IS NULL) OR (max_uses >= 1))),
    CONSTRAINT invites_uses_check CHECK ((uses >= 0))
);


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: journey_progress; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.journey_progress (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    user_id uuid NOT NULL,
    journey_id character varying(64) NOT NULL,
    steps_done jsonb DEFAULT '[]'::jsonb NOT NULL,
    completed_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone
);


--
-- Name: judicial_nominations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.judicial_nominations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    judiciary_id uuid NOT NULL,
    seat_id uuid,
    mode character varying(20) NOT NULL,
    nominating_jurisdiction_id uuid,
    nominee_user_id uuid NOT NULL,
    appointment_id uuid,
    dossier_record_id uuid,
    status character varying(16) NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT judicial_nominations_mode_check CHECK (((mode)::text = ANY ((ARRAY['constituent'::character varying, 'committee'::character varying])::text[]))),
    CONSTRAINT judicial_nominations_status_check CHECK (((status)::text = ANY ((ARRAY['nominated'::character varying, 'consented'::character varying, 'rejected'::character varying, 'withdrawn'::character varying])::text[])))
);


--
-- Name: judicial_seats; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.judicial_seats (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    judiciary_id uuid NOT NULL,
    user_id uuid,
    seat_number smallint NOT NULL,
    term_starts_on date,
    term_ends_on date,
    status character varying(16) DEFAULT 'vacant'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    seat_class character varying(24) DEFAULT 'committee_nominated'::character varying NOT NULL,
    nominating_jurisdiction_id uuid,
    appointment_id uuid,
    elected_in_race_id uuid,
    term_id uuid,
    CONSTRAINT judicial_seats_seat_class_check CHECK (((seat_class)::text = ANY ((ARRAY['constituent_nominated'::character varying, 'committee_nominated'::character varying, 'elected'::character varying])::text[]))),
    CONSTRAINT judicial_seats_status_check CHECK (((status)::text = ANY ((ARRAY['vacant'::character varying, 'nominated'::character varying, 'seated'::character varying, 'removal_requested'::character varying, 'removed'::character varying, 'term_ended'::character varying, 'retired'::character varying])::text[])))
);


--
-- Name: judiciaries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.judiciaries (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    court_name character varying(128) DEFAULT 'Superior Court'::character varying NOT NULL,
    type character varying(16) DEFAULT 'appointed'::character varying NOT NULL,
    min_judges smallint DEFAULT '5'::smallint NOT NULL,
    term_years smallint DEFAULT '10'::smallint NOT NULL,
    status character varying(16) DEFAULT 'forming'::character varying NOT NULL,
    parent_judiciary_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    creation_law_id uuid,
    nomination_mode character varying(20),
    conversion_process_id uuid,
    conversion_law_id uuid,
    converted_at timestamp(0) with time zone,
    judge_count smallint,
    source_legislature_id uuid,
    CONSTRAINT judiciaries_judge_count_check CHECK (((judge_count IS NULL) OR (judge_count >= min_judges))),
    CONSTRAINT judiciaries_min_judges_check CHECK ((min_judges >= 5)),
    CONSTRAINT judiciaries_nomination_mode_check CHECK (((nomination_mode IS NULL) OR ((nomination_mode)::text = ANY ((ARRAY['constituent'::character varying, 'committee'::character varying])::text[])))),
    CONSTRAINT judiciaries_status_check CHECK (((status)::text = ANY ((ARRAY['forming'::character varying, 'creating'::character varying, 'appointed'::character varying, 'conversion_voted'::character varying, 'elected'::character varying, 'dissolved'::character varying, 'reverted'::character varying])::text[]))),
    CONSTRAINT judiciaries_type_check CHECK (((type)::text = ANY ((ARRAY['appointed'::character varying, 'elected'::character varying])::text[])))
);


--
-- Name: juries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.juries (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    case_id uuid NOT NULL,
    selection_order_id uuid,
    pool_size integer NOT NULL,
    eligible_jurisdiction_id uuid NOT NULL,
    seats smallint DEFAULT '12'::smallint NOT NULL,
    alternates smallint DEFAULT '2'::smallint NOT NULL,
    draw_seed character varying(64) NOT NULL,
    report_on timestamp(0) with time zone,
    status character varying(16) NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT juries_status_check CHECK (((status)::text = ANY ((ARRAY['drawing'::character varying, 'voir_dire'::character varying, 'empaneled'::character varying, 'deliberating'::character varying, 'discharged'::character varying])::text[])))
);


--
-- Name: jurisdiction_activations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jurisdiction_activations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    state character varying(24) DEFAULT 'boundary_loaded'::character varying NOT NULL,
    critical_population_at timestamp(0) with time zone,
    activated_at timestamp(0) with time zone,
    legislature_id uuid,
    notes jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT jurisdiction_activations_state_check CHECK (((state)::text = ANY ((ARRAY['boundary_loaded'::character varying, 'critical_population'::character varying, 'bootstrapping'::character varying, 'self_governing'::character varying])::text[])))
);


--
-- Name: jurisdiction_maps; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jurisdiction_maps (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    root_jurisdiction_id uuid NOT NULL,
    name character varying(160) NOT NULL,
    description text,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    version_no integer DEFAULT 1 NOT NULL,
    origin character varying(24),
    origin_process_id uuid,
    effective_start date,
    effective_end date,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT jurisdiction_maps_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying, 'archived'::character varying])::text[])))
);


--
-- Name: jurisdictions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jurisdictions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    iso_code character varying(255),
    adm_level smallint DEFAULT '4'::smallint NOT NULL,
    parent_id uuid,
    population bigint,
    population_year smallint,
    is_active boolean DEFAULT true NOT NULL,
    authoritative_server_id uuid,
    authoritative_server_url character varying(255),
    last_synced_at timestamp(0) without time zone,
    source character varying(255) DEFAULT 'user_defined'::character varying NOT NULL,
    geoboundaries_id character varying(255),
    official_languages json DEFAULT '["en"]'::json NOT NULL,
    timezone character varying(255) DEFAULT 'UTC'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    geom public.geometry(MultiPolygon,4326),
    centroid public.geometry(Point,4326),
    is_civic_active boolean DEFAULT true NOT NULL,
    parent_assigned_via character varying(32),
    population_assigned_via character varying(32),
    population_baseline bigint,
    map_id uuid,
    lifecycle_status character varying(24)
);


--
-- Name: COLUMN jurisdictions.slug; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.jurisdictions.slug IS 'URL-safe identifier';


--
-- Name: COLUMN jurisdictions.iso_code; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.jurisdictions.iso_code IS 'ISO 3166 code where applicable';


--
-- Name: COLUMN jurisdictions.authoritative_server_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.jurisdictions.authoritative_server_id IS 'NULL = this server is authoritative';


--
-- Name: COLUMN jurisdictions.source; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.jurisdictions.source IS 'geoboundaries|osm|user_defined|computed_skater';


--
-- Name: COLUMN jurisdictions.is_civic_active; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.jurisdictions.is_civic_active IS 'Instance-maintainer flag: whether this jurisdiction participates in civic cycles (elections, legislatures). Toggled by the setup-wizard tree picker.';


--
-- Name: COLUMN jurisdictions.population_baseline; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.jurisdictions.population_baseline IS 'Phase T.3: pre-correction population. Snapshotted once per ISO at the first pixel-attribution-correction run; never overwritten after that.';


--
-- Name: COLUMN jurisdictions.map_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.jurisdictions.map_id IS 'Phase F: the jurisdiction_map version that placed this row';


--
-- Name: COLUMN jurisdictions.lifecycle_status; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.jurisdictions.lifecycle_status IS 'Phase F: self_governing|in_union|intermediary|disintermediated|restoration|…';


--
-- Name: jury_members; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jury_members (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jury_id uuid NOT NULL,
    user_id uuid NOT NULL,
    seat_kind character varying(12) NOT NULL,
    seat_no smallint,
    screening_status character varying(16) NOT NULL,
    excusal_reason character varying(24),
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT jury_members_excusal_reason_check CHECK (((excusal_reason IS NULL) OR ((excusal_reason)::text = ANY ((ARRAY['conflict'::character varying, 'hardship'::character varying])::text[])))),
    CONSTRAINT jury_members_screening_status_check CHECK (((screening_status)::text = ANY ((ARRAY['summoned'::character varying, 'screening'::character varying, 'cleared'::character varying, 'excused'::character varying, 'empaneled'::character varying, 'discharged'::character varying])::text[]))),
    CONSTRAINT jury_members_seat_kind_check CHECK (((seat_kind)::text = ANY ((ARRAY['juror'::character varying, 'alternate'::character varying])::text[])))
);


--
-- Name: law_merge_resolutions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.law_merge_resolutions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    process_id uuid NOT NULL,
    law_id uuid NOT NULL,
    target_jurisdiction_id uuid,
    decision character varying(12) NOT NULL,
    resulting_law_id uuid,
    resolved_by uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT law_merge_resolutions_decision_check CHECK (((decision)::text = ANY ((ARRAY['incorporate'::character varying, 'defer'::character varying, 'lapse'::character varying])::text[])))
);


--
-- Name: law_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.law_versions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    law_id uuid NOT NULL,
    version_no smallint NOT NULL,
    text text NOT NULL,
    text_hash character(64) NOT NULL,
    source character varying(24) NOT NULL,
    source_ref_type character varying(32),
    source_ref_id uuid,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT law_versions_source_check CHECK (((source)::text = ANY ((ARRAY['enactment'::character varying, 'legislative_amendment'::character varying, 'judicial_remedy'::character varying, 'referendum_modification'::character varying, 'merge_incorporation'::character varying])::text[])))
);


--
-- Name: laws; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.laws (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    legislature_id uuid NOT NULL,
    act_number character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    kind character varying(24) NOT NULL,
    scale jsonb NOT NULL,
    scope_judiciary_id uuid,
    origin character varying(20) NOT NULL,
    enacting_bill_id uuid,
    origin_ref_type character varying(32),
    origin_ref_id uuid,
    referendum_passed_by_supermajority boolean,
    shield_expires_with_election_id uuid,
    status character varying(12) DEFAULT 'in_force'::character varying NOT NULL,
    current_version_no smallint DEFAULT '1'::smallint NOT NULL,
    effective_at timestamp(0) with time zone NOT NULL,
    enacted_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT laws_kind_check CHECK (((kind)::text = ANY ((ARRAY['ordinary'::character varying, 'setting_change'::character varying, 'rules_of_order'::character varying, 'ethics_code'::character varying, 'charter'::character varying, 'creation_act'::character varying, 'referendum_act'::character varying, 'constitutional_article'::character varying])::text[]))),
    CONSTRAINT laws_origin_check CHECK (((origin)::text = ANY ((ARRAY['bill'::character varying, 'referendum'::character varying, 'petition_initiative'::character varying, 'judicial_remedy'::character varying, 'founding'::character varying])::text[]))),
    CONSTRAINT laws_status_check CHECK (((status)::text = ANY ((ARRAY['in_force'::character varying, 'amended'::character varying, 'repealed'::character varying, 'superseded'::character varying, 'struck'::character varying])::text[])))
);


--
-- Name: legal_compliance_removals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legal_compliance_removals (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    matrix_event_id character varying(255),
    matrix_room_id character varying(255),
    operator_account_id uuid NOT NULL,
    legal_basis character varying(24) NOT NULL,
    action character varying(16) NOT NULL,
    statutory_citation text,
    matched_list_source character varying(120),
    public_records_id uuid,
    jurisdiction_id uuid,
    is_seated_at_time boolean NOT NULL,
    referral_record_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    physical_removal_status character varying(16) DEFAULT 'deferred'::character varying NOT NULL,
    CONSTRAINT legal_compliance_removals_action_check CHECK (((action)::text = ANY ((ARRAY['purge'::character varying, 'soft_fail'::character varying, 'hard_redact'::character varying])::text[]))),
    CONSTRAINT legal_compliance_removals_basis_check CHECK (((legal_basis)::text = ANY ((ARRAY['csam_hashmatch'::character varying, 'court_order_specific'::character varying, 'true_threat'::character varying])::text[]))),
    CONSTRAINT legal_compliance_removals_physical_status_check CHECK (((physical_removal_status)::text = ANY ((ARRAY['deferred'::character varying, 'done'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: legislature_district_jurisdictions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legislature_district_jurisdictions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    district_id uuid NOT NULL,
    jurisdiction_id uuid,
    subdivision_id uuid,
    CONSTRAINT ldj_member_kind_xor_check CHECK (((((jurisdiction_id IS NOT NULL))::integer + ((subdivision_id IS NOT NULL))::integer) = 1))
);


--
-- Name: legislature_district_maps; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legislature_district_maps (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    legislature_id uuid NOT NULL,
    name character varying(120) NOT NULL,
    description text,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    effective_start date,
    effective_end date,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: legislature_districts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legislature_districts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    legislature_id uuid NOT NULL,
    jurisdiction_id uuid,
    district_number smallint NOT NULL,
    seats smallint NOT NULL,
    target_population bigint NOT NULL,
    actual_population bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    status character varying(30) DEFAULT 'phase1_complete'::character varying NOT NULL,
    fractional_seats numeric(10,6),
    floor_override boolean DEFAULT false NOT NULL,
    map_id uuid,
    num_geom_parts integer,
    is_contiguous boolean,
    convex_hull_ratio numeric(8,6)
);


--
-- Name: legislature_members; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legislature_members (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    legislature_id uuid NOT NULL,
    user_id uuid NOT NULL,
    seat_type character(1) DEFAULT 'a'::bpchar NOT NULL,
    district_id uuid,
    seated_on date,
    term_ends_on date,
    status character varying(255) DEFAULT 'elected'::character varying NOT NULL,
    vacated_at timestamp(0) without time zone,
    vacancy_reason character varying(255),
    election_id uuid,
    is_speaker boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    seat_no smallint,
    elected_in_race_id uuid,
    term_id uuid,
    vote_share_norm numeric(8,4),
    seated_at timestamp(0) with time zone,
    home_jurisdiction_id uuid,
    CONSTRAINT legislature_members_status_check CHECK (((status)::text = ANY ((ARRAY['elected'::character varying, 'seated'::character varying, 'vacated'::character varying, 'removed'::character varying, 'term_ended'::character varying])::text[])))
);


--
-- Name: COLUMN legislature_members.district_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.legislature_members.district_id IS 'FK to districts table (future)';


--
-- Name: COLUMN legislature_members.election_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.legislature_members.election_id IS 'FK to elections table';


--
-- Name: legislature_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legislature_sessions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    legislature_id uuid NOT NULL,
    session_no integer NOT NULL,
    called_by_member_id uuid,
    scheduled_for timestamp(0) with time zone,
    opened_at timestamp(0) with time zone,
    adjourned_at timestamp(0) with time zone,
    serving_at_open smallint,
    quorum_required smallint,
    serving_by_kind jsonb,
    quorum_required_by_kind jsonb,
    quorum_met boolean,
    agenda jsonb DEFAULT '[]'::jsonb NOT NULL,
    minutes_record_id uuid,
    status character varying(16) DEFAULT 'scheduled'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT legislature_sessions_status_check CHECK (((status)::text = ANY ((ARRAY['scheduled'::character varying, 'open'::character varying, 'adjourned'::character varying, 'failed_quorum'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: legislatures; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legislatures (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    term_number smallint DEFAULT '1'::smallint NOT NULL,
    term_starts_on date,
    term_ends_on date,
    status character varying(255) DEFAULT 'forming'::character varying NOT NULL,
    total_seats smallint DEFAULT '5'::smallint NOT NULL,
    type_a_seats smallint DEFAULT '5'::smallint NOT NULL,
    type_b_seats smallint DEFAULT '0'::smallint NOT NULL,
    speaker_id uuid,
    quorum_required smallint DEFAULT '3'::smallint NOT NULL,
    last_met_on date,
    next_meeting_due_by date,
    parent_legislature_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: COLUMN legislatures.total_seats; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.legislatures.total_seats IS 'Between 5 and 9 per constitutional settings';


--
-- Name: COLUMN legislatures.speaker_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.legislatures.speaker_id IS 'FK to legislature_members added later';


--
-- Name: local_autonomy_processes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.local_autonomy_processes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    promoting_jurisdiction_id uuid NOT NULL,
    promoting_legislature_id uuid NOT NULL,
    parent_jurisdiction_id uuid NOT NULL,
    gaining_server_id uuid NOT NULL,
    gaining_cluster_id uuid,
    parent_process_id uuid NOT NULL,
    promoting_supermajority_met boolean DEFAULT false NOT NULL,
    status character varying(12) DEFAULT 'open'::character varying NOT NULL,
    resulting_authoritative_server_id uuid,
    subtree_size integer,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT local_autonomy_processes_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'passed'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: location_pings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.location_pings (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    user_id uuid NOT NULL,
    latitude numeric(10,7) NOT NULL,
    longitude numeric(10,7) NOT NULL,
    accuracy_meters numeric(8,2),
    source character varying(255) DEFAULT 'manual'::character varying NOT NULL,
    pinged_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    geom public.geometry(Point,4326),
    claim_id uuid,
    is_qualifying boolean,
    evaluated_at timestamp(0) with time zone,
    CONSTRAINT location_pings_source_check CHECK (((source)::text = ANY ((ARRAY['mobile'::character varying, 'web'::character varying, 'manual'::character varying, 'simulated'::character varying])::text[])))
);


--
-- Name: matrix_carveout_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.matrix_carveout_log (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    matrix_room_id character varying(255) NOT NULL,
    matrix_event_id character varying(255),
    carve_out character varying(16) NOT NULL,
    action character varying(16) NOT NULL,
    attestation_id uuid,
    issuer_server_id character varying(255),
    public_records_id uuid,
    jurisdiction_id uuid,
    is_seated_at_time boolean NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT matrix_carveout_log_action_check CHECK (((action)::text = ANY ((ARRAY['soft_fail'::character varying, 'hard_redact'::character varying, 'server_acl'::character varying, 'purge'::character varying])::text[]))),
    CONSTRAINT matrix_carveout_log_attestation_seated_check CHECK (((attestation_id IS NULL) OR (is_seated_at_time = true))),
    CONSTRAINT matrix_carveout_log_carve_out_check CHECK (((carve_out)::text = ANY ((ARRAY['m1_judicial'::character varying, 'm2_rights'::character varying, 'm4_antispam'::character varying, 'm5_legal'::character varying])::text[])))
);


--
-- Name: matrix_event_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.matrix_event_snapshots (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    matrix_event_id character varying(255) NOT NULL,
    matrix_room_id character varying(255) NOT NULL,
    published_record_id uuid NOT NULL,
    actor_display character varying(120) NOT NULL,
    origin_server_ts bigint,
    body_snapshot text NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone
);


--
-- Name: matrix_identities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.matrix_identities (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    user_id uuid NOT NULL,
    matrix_localpart character varying(64) NOT NULL,
    matrix_user_id character varying(255),
    device_master_key character varying(255),
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone
);


--
-- Name: matrix_rooms; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.matrix_rooms (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    matrix_room_id character varying(255),
    matrix_alias character varying(255),
    room_type character varying(20) NOT NULL,
    room_version character varying(8),
    entity_type character varying(24) NOT NULL,
    entity_id uuid NOT NULL,
    space_type character varying(16),
    is_public boolean DEFAULT true NOT NULL,
    is_seated boolean DEFAULT false NOT NULL,
    is_activated boolean DEFAULT true NOT NULL,
    tombstoned_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    is_encrypted boolean DEFAULT false NOT NULL,
    CONSTRAINT matrix_rooms_entity_type_check CHECK (((entity_type)::text = ANY ((ARRAY['jurisdiction'::character varying, 'organization'::character varying, 'legislature'::character varying, 'executive'::character varying, 'judiciary'::character varying, 'board'::character varying, 'bill'::character varying, 'referendum_question'::character varying, 'petition'::character varying, 'committee_meeting'::character varying, 'candidacy'::character varying, 'social_space'::character varying])::text[]))),
    CONSTRAINT matrix_rooms_room_type_check CHECK (((room_type)::text = ANY ((ARRAY['m.space'::character varying, 'commons'::character varying, 'org_public'::character varying, 'org_private'::character varying, 'institution'::character varying, 'user_private'::character varying])::text[]))),
    CONSTRAINT matrix_rooms_space_type_check CHECK (((space_type IS NULL) OR ((space_type)::text = ANY ((ARRAY['public_square'::character varying, 'halls'::character varying])::text[]))))
);


--
-- Name: matrix_server_acls; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.matrix_server_acls (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    matrix_room_id character varying(255) NOT NULL,
    allow jsonb DEFAULT '[]'::jsonb NOT NULL,
    deny jsonb DEFAULT '[]'::jsonb NOT NULL,
    written_by_carve_out character varying(16) NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT matrix_server_acls_carve_out_check CHECK (((written_by_carve_out)::text = ANY ((ARRAY['m1_judicial'::character varying, 'm4_antispam'::character varying])::text[])))
);


--
-- Name: mesh_operator_identities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mesh_operator_identities (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    display_handle character varying(255) NOT NULL,
    genesis_server_id uuid NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone
);


--
-- Name: mesh_operator_keys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mesh_operator_keys (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    mesh_operator_id uuid NOT NULL,
    device_public_key text NOT NULL,
    bound_by_server_id uuid NOT NULL,
    binding_signature text NOT NULL,
    status character varying(16) DEFAULT 'active'::character varying NOT NULL,
    bound_at timestamp(0) with time zone NOT NULL,
    revoked_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT mesh_operator_keys_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'revoked'::character varying])::text[])))
);


--
-- Name: mesh_operator_local_links; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mesh_operator_local_links (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    operator_account_id uuid NOT NULL,
    mesh_operator_id uuid NOT NULL,
    linked_via_peer_id uuid,
    linked_at timestamp(0) with time zone NOT NULL,
    unlinked_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone
);


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: misconduct_investigations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.misconduct_investigations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    admin_office_id uuid NOT NULL,
    code character varying(16) NOT NULL,
    subject_type character varying(40) NOT NULL,
    subject_id uuid NOT NULL,
    complainant_user_id uuid,
    summary text NOT NULL,
    status character varying(24) DEFAULT 'intake'::character varying NOT NULL,
    findings_record_id uuid,
    referred_proceeding_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT misconduct_investigations_status_check CHECK (((status)::text = ANY ((ARRAY['intake'::character varying, 'investigating'::character varying, 'referred'::character varying, 'closed_no_finding'::character varying])::text[])))
);


--
-- Name: motions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.motions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    session_id uuid NOT NULL,
    bill_id uuid,
    moved_by_member_id uuid NOT NULL,
    seconded_by_member_id uuid,
    text text NOT NULL,
    kind character varying(20) NOT NULL,
    status character varying(12) DEFAULT 'submitted'::character varying NOT NULL,
    vote_id uuid,
    amendment_text text,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT motions_kind_check CHECK (((kind)::text = ANY ((ARRAY['procedural'::character varying, 'referral'::character varying, 'direct_to_floor'::character varying, 'amendment'::character varying, 'table'::character varying, 'adjourn'::character varying, 'replace_speaker'::character varying, 'other'::character varying])::text[]))),
    CONSTRAINT motions_status_check CHECK (((status)::text = ANY ((ARRAY['submitted'::character varying, 'recognized'::character varying, 'debated'::character varying, 'voted'::character varying, 'adopted'::character varying, 'failed'::character varying, 'withdrawn'::character varying])::text[])))
);


--
-- Name: multi_jurisdiction_votes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.multi_jurisdiction_votes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    kind character varying(24) NOT NULL,
    subject_type character varying(40),
    subject_id uuid,
    initiating_legislature_id uuid NOT NULL,
    initiating_vote_id uuid,
    basis character varying(16) NOT NULL,
    constituent_total smallint NOT NULL,
    required smallint NOT NULL,
    yes_count smallint DEFAULT '0'::smallint NOT NULL,
    no_count smallint DEFAULT '0'::smallint NOT NULL,
    status character varying(8) DEFAULT 'open'::character varying NOT NULL,
    opens_at timestamp(0) with time zone,
    closes_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT multi_jurisdiction_votes_basis_check CHECK (((basis)::text = ANY ((ARRAY['supermajority'::character varying, 'unanimity'::character varying])::text[]))),
    CONSTRAINT multi_jurisdiction_votes_kind_check CHECK (((kind)::text = ANY ((ARRAY['exec_office_create'::character varying, 'exec_office_alter'::character varying, 'judiciary_convert'::character varying, 'cultural_institution'::character varying, 'additional_articles'::character varying, 'union'::character varying, 'disintermediation'::character varying, 'setting_amendment'::character varying, 'local_autonomy'::character varying, 'peer_upgrade'::character varying])::text[]))),
    CONSTRAINT multi_jurisdiction_votes_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'passed'::character varying, 'failed'::character varying, 'expired'::character varying])::text[])))
);


--
-- Name: oidc_authorization_codes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.oidc_authorization_codes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    code_hash character varying(64) NOT NULL,
    client_id character varying(128) NOT NULL,
    user_id uuid NOT NULL,
    redirect_uri text NOT NULL,
    scope character varying(255) DEFAULT 'openid'::character varying NOT NULL,
    code_challenge character varying(255) NOT NULL,
    nonce character varying(255),
    expires_at timestamp(0) with time zone NOT NULL,
    consumed_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone
);


--
-- Name: oidc_signing_keys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.oidc_signing_keys (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    kid character varying(255) NOT NULL,
    algorithm character varying(12) DEFAULT 'RS256'::character varying NOT NULL,
    public_jwk jsonb NOT NULL,
    private_pem_encrypted text NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    rotated_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone
);


--
-- Name: operational_partition_exports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.operational_partition_exports (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    root_jurisdiction_id uuid,
    direction character varying(8) NOT NULL,
    peer_server_id uuid,
    election_count integer DEFAULT 0 NOT NULL,
    applied_count integer DEFAULT 0 NOT NULL,
    sealed_fingerprint character varying(128),
    status character varying(12) DEFAULT 'sealed'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT operational_partition_exports_direction_check CHECK (((direction)::text = ANY ((ARRAY['outbound'::character varying, 'inbound'::character varying])::text[])))
);


--
-- Name: operator_accounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.operator_accounts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    server_id uuid NOT NULL,
    username character varying(255) NOT NULL,
    password character varying(255) NOT NULL,
    mesh_operator_id uuid,
    status character varying(16) DEFAULT 'active'::character varying NOT NULL,
    last_login_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT operator_accounts_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'suspended'::character varying, 'closed'::character varying])::text[])))
);


--
-- Name: operator_devices; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.operator_devices (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    operator_account_id uuid NOT NULL,
    device_public_key text NOT NULL,
    label character varying(255),
    enrolled_at timestamp(0) with time zone NOT NULL,
    revoked_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone
);


--
-- Name: opinion_law_links; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.opinion_law_links (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    opinion_id uuid NOT NULL,
    law_id uuid NOT NULL,
    law_version_no smallint,
    relation character varying(12) NOT NULL,
    note text,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT opinion_law_links_relation_check CHECK (((relation)::text = ANY ((ARRAY['cites'::character varying, 'interprets'::character varying, 'distinguishes'::character varying, 'applies'::character varying])::text[])))
);


--
-- Name: opinions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.opinions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    case_id uuid NOT NULL,
    panel_id uuid NOT NULL,
    authored_by_seat_id uuid NOT NULL,
    kind character varying(12) NOT NULL,
    title character varying(255) NOT NULL,
    body text NOT NULL,
    record_id uuid,
    published_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT opinions_kind_check CHECK (((kind)::text = ANY ((ARRAY['majority'::character varying, 'concurrence'::character varying, 'dissent'::character varying])::text[])))
);


--
-- Name: org_contracts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.org_contracts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    counterparty_type character varying(16) NOT NULL,
    counterparty_id uuid NOT NULL,
    kind character varying(16) NOT NULL,
    terms text NOT NULL,
    signed_by_org_user_id uuid,
    signed_by_org_at timestamp(0) with time zone,
    signed_by_counterparty_at timestamp(0) with time zone,
    status character varying(8) DEFAULT 'draft'::character varying NOT NULL,
    effective_at timestamp(0) with time zone,
    ended_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT org_contracts_cosign_check CHECK ((((status)::text <> 'active'::text) OR ((signed_by_org_at IS NOT NULL) AND (signed_by_counterparty_at IS NOT NULL)))),
    CONSTRAINT org_contracts_counterparty_type_check CHECK (((counterparty_type)::text = ANY ((ARRAY['users'::character varying, 'organizations'::character varying])::text[]))),
    CONSTRAINT org_contracts_kind_check CHECK (((kind)::text = ANY ((ARRAY['labor_recurring'::character varying, 'labor_single'::character varying, 'commercial'::character varying, 'other'::character varying])::text[]))),
    CONSTRAINT org_contracts_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'offered'::character varying, 'active'::character varying, 'ended'::character varying, 'voided'::character varying])::text[])))
);


--
-- Name: org_conversions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.org_conversions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    direction character varying(16) NOT NULL,
    via character varying(24) NOT NULL,
    proposal_id uuid,
    authorizing_vote_id uuid,
    authorizing_law_id uuid,
    fair_market_floor numeric(18,2),
    fair_market_basis text,
    compensation numeric(18,2),
    compensation_record_id uuid,
    board_transition jsonb DEFAULT '[]'::jsonb NOT NULL,
    status character varying(24) DEFAULT 'proposed'::character varying NOT NULL,
    completed_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT org_conversions_direction_check CHECK (((direction)::text = ANY ((ARRAY['private_to_cgc'::character varying, 'cgc_to_private'::character varying])::text[]))),
    CONSTRAINT org_conversions_fair_market_check CHECK (((compensation IS NULL) OR (fair_market_floor IS NULL) OR (compensation >= fair_market_floor))),
    CONSTRAINT org_conversions_status_check CHECK (((status)::text = ANY ((ARRAY['proposed'::character varying, 'voted'::character varying, 'compensation_pending'::character varying, 'converting'::character varying, 'completed'::character varying, 'abandoned'::character varying])::text[]))),
    CONSTRAINT org_conversions_via_check CHECK (((via)::text = ANY ((ARRAY['mutual'::character varying, 'monopoly_acquisition'::character varying, 'cgc_sale'::character varying])::text[])))
);


--
-- Name: org_document_package_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.org_document_package_versions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    package_id uuid NOT NULL,
    version_no smallint NOT NULL,
    content text NOT NULL,
    created_by_user_id uuid,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: org_document_packages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.org_document_packages (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    key character varying(64) NOT NULL,
    name character varying(255) NOT NULL,
    kind character varying(20) NOT NULL,
    status character varying(8) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT org_document_packages_kind_check CHECK (((kind)::text = ANY ((ARRAY['charter'::character varying, 'bylaws'::character varying, 'hr_policy'::character varying, 'compensation_policy'::character varying, 'custom_form'::character varying, 'other'::character varying])::text[]))),
    CONSTRAINT org_document_packages_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'retired'::character varying])::text[])))
);


--
-- Name: org_memberships; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.org_memberships (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    user_id uuid NOT NULL,
    kind character varying(12) NOT NULL,
    status character varying(10) DEFAULT 'applied'::character varying NOT NULL,
    applied_at timestamp(0) with time zone NOT NULL,
    accepted_at timestamp(0) with time zone,
    ended_at timestamp(0) with time zone,
    accepted_by_user_id uuid,
    end_reason character varying(24),
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT org_memberships_end_reason_check CHECK (((end_reason IS NULL) OR ((end_reason)::text = ANY ((ARRAY['resigned'::character varying, 'removed'::character varying, 'transferred'::character varying, 'dissolved'::character varying])::text[])))),
    CONSTRAINT org_memberships_kind_check CHECK (((kind)::text = ANY ((ARRAY['member'::character varying, 'shareholder'::character varying, 'partner'::character varying])::text[]))),
    CONSTRAINT org_memberships_status_check CHECK (((status)::text = ANY ((ARRAY['applied'::character varying, 'active'::character varying, 'ended'::character varying, 'declined'::character varying])::text[])))
);


--
-- Name: org_ownership_stakes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.org_ownership_stakes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    holder_type character varying(16) NOT NULL,
    holder_id uuid NOT NULL,
    units numeric(20,6) NOT NULL,
    pct numeric(7,4),
    acquired_via character varying(12) NOT NULL,
    source_transfer_id uuid,
    as_of timestamp(0) with time zone NOT NULL,
    ended_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT org_ownership_stakes_acquired_via_check CHECK (((acquired_via)::text = ANY ((ARRAY['founding'::character varying, 'issue'::character varying, 'transfer'::character varying, 'conversion'::character varying])::text[]))),
    CONSTRAINT org_ownership_stakes_holder_type_check CHECK (((holder_type)::text = ANY ((ARRAY['users'::character varying, 'organizations'::character varying, 'jurisdictions'::character varying])::text[]))),
    CONSTRAINT org_ownership_stakes_units_check CHECK ((units > (0)::numeric))
);


--
-- Name: org_transfers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.org_transfers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    to_party_type character varying(16) NOT NULL,
    to_party_id uuid NOT NULL,
    terms text,
    consent_from_at timestamp(0) with time zone,
    consent_from_user_id uuid,
    consent_to_at timestamp(0) with time zone,
    consent_to_user_id uuid,
    status character varying(10) DEFAULT 'proposed'::character varying NOT NULL,
    completed_at timestamp(0) with time zone,
    ffc_synced_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT org_transfers_mutual_consent_check CHECK ((((status)::text <> ALL ((ARRAY['consented'::character varying, 'completed'::character varying])::text[])) OR ((consent_from_at IS NOT NULL) AND (consent_to_at IS NOT NULL)))),
    CONSTRAINT org_transfers_status_check CHECK (((status)::text = ANY ((ARRAY['proposed'::character varying, 'consented'::character varying, 'completed'::character varying, 'abandoned'::character varying])::text[]))),
    CONSTRAINT org_transfers_to_party_type_check CHECK (((to_party_type)::text = ANY ((ARRAY['users'::character varying, 'organizations'::character varying])::text[])))
);


--
-- Name: org_workers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.org_workers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    employer_type character varying(16) NOT NULL,
    employer_id uuid NOT NULL,
    user_id uuid NOT NULL,
    contract_id uuid,
    status character varying(10) DEFAULT 'applied'::character varying NOT NULL,
    started_at timestamp(0) with time zone,
    ended_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT org_workers_employer_type_check CHECK (((employer_type)::text = ANY ((ARRAY['organizations'::character varying, 'departments'::character varying])::text[]))),
    CONSTRAINT org_workers_status_check CHECK (((status)::text = ANY ((ARRAY['applied'::character varying, 'active'::character varying, 'ended'::character varying])::text[])))
);


--
-- Name: organizations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organizations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    type character varying(255) DEFAULT 'informal'::character varying NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    abbreviation character varying(255),
    color character varying(7),
    description text,
    website_url character varying(255),
    parent_organization_id uuid,
    is_cgc boolean DEFAULT false NOT NULL,
    created_by_legislature_id uuid,
    overseen_by_executive_id uuid,
    ownership_type character varying(255) DEFAULT 'private'::character varying NOT NULL,
    worker_count integer DEFAULT 0 NOT NULL,
    ip_is_public_domain boolean DEFAULT false NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    is_registered boolean DEFAULT false NOT NULL,
    registered_at timestamp(0) without time zone,
    dissolved_at timestamp(0) without time zone,
    dissolution_reason character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    agent_user_id uuid,
    structure character varying(20),
    status character varying(16) DEFAULT 'registered'::character varying NOT NULL,
    registered_by_user_id uuid,
    registered_via_form character varying(12),
    purpose text,
    created_by_law_id uuid,
    board_id uuid,
    registration_record_id uuid,
    CONSTRAINT organizations_status_check CHECK (((status)::text = ANY ((ARRAY['registered'::character varying, 'active'::character varying, 'transfer_pending'::character varying, 'transferred'::character varying, 'converted'::character varying, 'dissolved'::character varying])::text[]))),
    CONSTRAINT organizations_structure_check CHECK (((structure IS NULL) OR ((structure)::text = ANY ((ARRAY['stock'::character varying, 'partnership'::character varying, 'equal_partnership'::character varying, 'member_owned'::character varying, 'worker_owned'::character varying, 'nonprofit'::character varying])::text[]))))
);


--
-- Name: COLUMN organizations.color; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.organizations.color IS 'Hex color for political parties, e.g. #FF5733';


--
-- Name: COLUMN organizations.is_cgc; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.organizations.is_cgc IS 'True = legislature-created Common Good Corporation';


--
-- Name: COLUMN organizations.created_by_legislature_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.organizations.created_by_legislature_id IS 'For CGCs: which legislature created this organization';


--
-- Name: COLUMN organizations.overseen_by_executive_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.organizations.overseen_by_executive_id IS 'For CGCs: which executive body oversees this';


--
-- Name: COLUMN organizations.ip_is_public_domain; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.organizations.ip_is_public_domain IS 'Always true for CGCs per Article III Sec 5';


--
-- Name: COLUMN organizations.agent_user_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.organizations.agent_user_id IS 'R-23 role gate for F-ORG-002 (full org module is Phase D)';


--
-- Name: panel_judges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.panel_judges (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    panel_id uuid NOT NULL,
    judicial_seat_id uuid NOT NULL,
    user_id uuid,
    is_presiding boolean DEFAULT false NOT NULL,
    screening_result character varying(16) DEFAULT 'pending'::character varying NOT NULL,
    recusal_reason text,
    status character varying(12) NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT panel_judges_screening_result_check CHECK (((screening_result)::text = ANY ((ARRAY['pending'::character varying, 'cleared'::character varying, 'recused'::character varying])::text[]))),
    CONSTRAINT panel_judges_status_check CHECK (((status)::text = ANY ((ARRAY['drawn'::character varying, 'seated'::character varying, 'recused'::character varying, 'replaced'::character varying])::text[])))
);


--
-- Name: panels; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.panels (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    case_id uuid NOT NULL,
    judiciary_id uuid NOT NULL,
    size smallint NOT NULL,
    is_en_banc boolean DEFAULT false NOT NULL,
    severity_basis character varying(20) NOT NULL,
    presiding_judge_seat_id uuid,
    draw_seed character varying(64),
    status character varying(16) NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT panels_severity_basis_check CHECK (((severity_basis)::text = ANY ((ARRAY['minor'::character varying, 'moderate'::character varying, 'serious'::character varying, 'constitutional_major'::character varying])::text[]))),
    CONSTRAINT panels_size_odd_check CHECK (((size >= 3) AND (((size)::integer % 2) = 1))),
    CONSTRAINT panels_status_check CHECK (((status)::text = ANY ((ARRAY['drawing'::character varying, 'screening'::character varying, 'seated'::character varying, 'dissolved'::character varying])::text[])))
);


--
-- Name: partition_exports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.partition_exports (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    direction character varying(8) NOT NULL,
    peer_id uuid,
    manifest jsonb DEFAULT '{}'::jsonb NOT NULL,
    checksum character(64),
    checkpoint_audit_seq bigint,
    signed_by uuid,
    signature text,
    status character varying(20) DEFAULT 'prepared'::character varying NOT NULL,
    authority_flipped_at timestamp(0) with time zone,
    error text,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT partition_exports_direction_check CHECK (((direction)::text = ANY ((ARRAY['outbound'::character varying, 'inbound'::character varying])::text[]))),
    CONSTRAINT partition_exports_status_check CHECK (((status)::text = ANY ((ARRAY['prepared'::character varying, 'signed'::character varying, 'transmitted'::character varying, 'ingested'::character varying, 'flip_committed'::character varying, 'failed'::character varying, 'reverted'::character varying])::text[])))
);


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: peer_upgrade_consents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.peer_upgrade_consents (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    proposal_id uuid NOT NULL,
    meter character varying(10) NOT NULL,
    operator_account_id uuid,
    mesh_operator_id uuid,
    peer_server_id uuid,
    mjv_process_id uuid,
    result character varying(8) DEFAULT 'pending'::character varying NOT NULL,
    signature text,
    decided_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT peer_upgrade_consents_meter_check CHECK (((meter)::text = ANY ((ARRAY['operator'::character varying, 'seated'::character varying, 'peer'::character varying])::text[]))),
    CONSTRAINT peer_upgrade_consents_result_check CHECK (((result)::text = ANY ((ARRAY['pending'::character varying, 'yes'::character varying, 'no'::character varying])::text[])))
);


--
-- Name: peer_upgrade_proposals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.peer_upgrade_proposals (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    kind character varying(20) NOT NULL,
    from_constitutional_version character varying(255),
    to_constitutional_version character varying(255),
    from_schema_version character varying(255),
    to_schema_version character varying(255),
    from_app_release character varying(255),
    to_app_release character varying(255),
    hardened_params jsonb,
    affected_root_jurisdiction_id uuid NOT NULL,
    proposed_by_server_id uuid,
    signature text,
    status character varying(12) DEFAULT 'open'::character varying NOT NULL,
    seated_process_id uuid,
    ratified_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    capability character varying(32),
    grant_payload jsonb,
    CONSTRAINT peer_upgrade_proposals_kind_check CHECK (((kind)::text = ANY ((ARRAY['constitutional_bump'::character varying, 'schema_bump'::character varying, 'app_release'::character varying, 'role_grant'::character varying])::text[]))),
    CONSTRAINT peer_upgrade_proposals_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'ratified'::character varying, 'rejected'::character varying, 'superseded'::character varying])::text[])))
);


--
-- Name: petition_signatures; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.petition_signatures (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    petition_id uuid NOT NULL,
    user_id uuid NOT NULL,
    association_id uuid,
    signed_at timestamp(0) with time zone NOT NULL,
    revoked_at timestamp(0) with time zone
);


--
-- Name: petitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.petitions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    creator_user_id uuid NOT NULL,
    jurisdiction_id uuid NOT NULL,
    title character varying(255) NOT NULL,
    law_text text NOT NULL,
    act_type character varying(20) NOT NULL,
    targets_setting_key character varying(255),
    proposed_value jsonb,
    scale jsonb NOT NULL,
    scope_judiciary_id uuid,
    population_basis integer NOT NULL,
    threshold_pct numeric(5,2) NOT NULL,
    threshold_count integer NOT NULL,
    status character varying(24) DEFAULT 'created'::character varying NOT NULL,
    audit_result jsonb,
    review_case_id uuid,
    review_stub boolean DEFAULT false NOT NULL,
    referendum_question_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    review_outcome character varying(16),
    CONSTRAINT petitions_act_type_check CHECK (((act_type)::text = ANY ((ARRAY['ordinary'::character varying, 'setting_change'::character varying, 'supermajority'::character varying])::text[]))),
    CONSTRAINT petitions_review_outcome_check CHECK (((review_outcome IS NULL) OR ((review_outcome)::text = ANY ((ARRAY['cleared'::character varying, 'struck'::character varying])::text[])))),
    CONSTRAINT petitions_setting_pairing_check CHECK ((((act_type)::text = 'setting_change'::text) = (targets_setting_key IS NOT NULL))),
    CONSTRAINT petitions_status_check CHECK (((status)::text = ANY ((ARRAY['created'::character varying, 'gathering'::character varying, 'threshold_reached'::character varying, 'signature_audit'::character varying, 'constitutional_review'::character varying, 'validated'::character varying, 'on_ballot'::character varying, 'adopted'::character varying, 'rejected'::character varying, 'invalidated'::character varying])::text[])))
);


--
-- Name: policy_proposals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.policy_proposals (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    executive_id uuid NOT NULL,
    department_id uuid NOT NULL,
    proposed_by_member_id uuid NOT NULL,
    title character varying(255) NOT NULL,
    text text NOT NULL,
    board_vote_id uuid,
    decision character varying(12) DEFAULT 'pending'::character varying NOT NULL,
    amended_text text,
    decided_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT policy_proposals_decision_check CHECK (((decision)::text = ANY ((ARRAY['pending'::character varying, 'adopted'::character varying, 'amended'::character varying, 'declined'::character varying])::text[])))
);


--
-- Name: public_records; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.public_records (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    kind character varying(24) NOT NULL,
    title character varying(255) NOT NULL,
    body text,
    actor_user_id uuid,
    actor_display character varying(255),
    jurisdiction_id uuid,
    legislature_id uuid,
    via_form character varying(16),
    via_workflow character varying(16),
    via_clock character varying(8),
    subject_type character varying(40),
    subject_id uuid,
    audit_seq bigint,
    translations jsonb DEFAULT '{}'::jsonb NOT NULL,
    supersedes_record_id uuid,
    published_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    seq bigint NOT NULL,
    source_server_id uuid,
    CONSTRAINT public_records_kind_check CHECK (((kind)::text = ANY ((ARRAY['registration'::character varying, 'residency'::character varying, 'participation'::character varying, 'statement'::character varying, 'vote'::character varying, 'bill'::character varying, 'act'::character varying, 'minutes'::character varying, 'opinion'::character varying, 'certification'::character varying, 'testimony'::character varying, 'violation'::character varying, 'correction'::character varying, 'other'::character varying, 'moderation_flip'::character varying, 'legal_compliance_removal'::character varying])::text[])))
);


--
-- Name: COLUMN public_records.source_server_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.public_records.source_server_id IS 'Phase F FF&C: origin peer server_id; NULL = locally published';


--
-- Name: public_records_seq_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.public_records ALTER COLUMN seq ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.public_records_seq_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: race_results; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.race_results (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tabulation_id uuid NOT NULL,
    candidacy_id uuid NOT NULL,
    round_elected smallint,
    seat_no smallint,
    vote_share_norm numeric(8,4),
    is_runner_up boolean DEFAULT false NOT NULL,
    runner_up_rank smallint,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: read_write_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.read_write_requests (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    applicant_server_id uuid NOT NULL,
    applicant_public_key text,
    root_jurisdiction_id uuid NOT NULL,
    status character varying(24) DEFAULT 'submitted'::character varying NOT NULL,
    autonomy_process_id uuid,
    note text,
    submitted_at timestamp(0) with time zone NOT NULL,
    resolved_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT read_write_requests_status_check CHECK (((status)::text = ANY ((ARRAY['submitted'::character varying, 'vote_opened'::character varying, 'granted'::character varying, 'denied'::character varying, 'withdrawn'::character varying])::text[])))
);


--
-- Name: referendum_questions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.referendum_questions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    origin character varying(12) NOT NULL,
    delegating_vote_id uuid,
    petition_id uuid,
    question text NOT NULL,
    law_text text NOT NULL,
    act_type character varying(20) NOT NULL,
    threshold character varying(16) NOT NULL,
    targets_setting_key character varying(255),
    proposed_value jsonb,
    election_id uuid,
    eligible_population integer,
    yes_count integer,
    no_count integer,
    status character varying(12) DEFAULT 'queued'::character varying NOT NULL,
    resulting_law_id uuid,
    certified_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT referendum_questions_act_type_check CHECK (((act_type)::text = ANY ((ARRAY['ordinary'::character varying, 'setting_change'::character varying, 'supermajority'::character varying])::text[]))),
    CONSTRAINT referendum_questions_one_origin_check CHECK (((((origin)::text = 'delegation'::text) AND (delegating_vote_id IS NOT NULL) AND (petition_id IS NULL)) OR (((origin)::text = 'petition'::text) AND (petition_id IS NOT NULL) AND (delegating_vote_id IS NULL)))),
    CONSTRAINT referendum_questions_origin_check CHECK (((origin)::text = ANY ((ARRAY['delegation'::character varying, 'petition'::character varying])::text[]))),
    CONSTRAINT referendum_questions_setting_pairing_check CHECK ((((act_type)::text = 'setting_change'::text) = (targets_setting_key IS NOT NULL))),
    CONSTRAINT referendum_questions_status_check CHECK (((status)::text = ANY ((ARRAY['queued'::character varying, 'scheduled'::character varying, 'voted'::character varying, 'passed'::character varying, 'failed'::character varying, 'invalidated'::character varying])::text[]))),
    CONSTRAINT referendum_questions_threshold_check CHECK ((((threshold)::text = ANY ((ARRAY['majority'::character varying, 'supermajority'::character varying])::text[])) AND (((act_type)::text = 'supermajority'::text) = ((threshold)::text = 'supermajority'::text))))
);


--
-- Name: remedy_recommendations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.remedy_recommendations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    finding_id uuid NOT NULL,
    challenge_id uuid NOT NULL,
    judiciary_id uuid NOT NULL,
    remedy_kind character varying(16) NOT NULL,
    recommended_text text,
    rationale_text text NOT NULL,
    remedy_timeframe_days smallint NOT NULL,
    veto_window_days smallint NOT NULL,
    remedy_due_at timestamp(0) with time zone NOT NULL,
    veto_closes_at timestamp(0) with time zone NOT NULL,
    clk11_timer_id uuid,
    clk12_timer_id uuid,
    record_id uuid,
    issued_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT remedy_recommendations_kind_check CHECK (((remedy_kind)::text = ANY ((ARRAY['modify'::character varying, 'remove'::character varying])::text[]))),
    CONSTRAINT remedy_recommendations_timeframe_check CHECK ((remedy_timeframe_days > 0)),
    CONSTRAINT remedy_recommendations_veto_check CHECK ((veto_window_days > 0))
);


--
-- Name: removal_proceedings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.removal_proceedings (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    legislature_id uuid NOT NULL,
    kind character varying(20) NOT NULL,
    subject_type character varying(40) NOT NULL,
    subject_id uuid NOT NULL,
    source_investigation_id uuid,
    presided_by_member_id uuid,
    opened_via character varying(16) NOT NULL,
    vote_id uuid,
    status character varying(24) DEFAULT 'opened'::character varying NOT NULL,
    outcome character varying(12),
    closed_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT removal_proceedings_kind_check CHECK (((kind)::text = ANY ((ARRAY['impeachment'::character varying, 'censure'::character varying, 'expulsion'::character varying, 'judge_removal'::character varying, 'executive_removal'::character varying])::text[]))),
    CONSTRAINT removal_proceedings_outcome_check CHECK (((outcome IS NULL) OR ((outcome)::text = ANY ((ARRAY['removed'::character varying, 'censured'::character varying, 'expelled'::character varying, 'retained'::character varying])::text[])))),
    CONSTRAINT removal_proceedings_status_check CHECK (((status)::text = ANY ((ARRAY['opened'::character varying, 'presiding_designated'::character varying, 'voted'::character varying, 'closed'::character varying])::text[])))
);


--
-- Name: residency_claims; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.residency_claims (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    user_id uuid NOT NULL,
    jurisdiction_id uuid NOT NULL,
    status character varying(24) DEFAULT 'declared'::character varying NOT NULL,
    declared_at timestamp(0) with time zone NOT NULL,
    ping_consent_at timestamp(0) with time zone NOT NULL,
    qualifying_days smallint DEFAULT '0'::smallint NOT NULL,
    threshold_days_at_verification smallint,
    threshold_met_at timestamp(0) with time zone,
    verified_at timestamp(0) with time zone,
    superseded_at timestamp(0) with time zone,
    lapsed_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT residency_claims_status_check CHECK (((status)::text = ANY ((ARRAY['declared'::character varying, 'ping_monitoring'::character varying, 'threshold_met'::character varying, 'verified'::character varying, 'active'::character varying, 'superseded'::character varying, 'lapsed'::character varying])::text[])))
);


--
-- Name: residency_confirmations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.residency_confirmations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    user_id uuid NOT NULL,
    jurisdiction_id uuid NOT NULL,
    days_confirmed smallint NOT NULL,
    confirmed_at timestamp(0) without time zone NOT NULL,
    voting_right_active boolean DEFAULT true NOT NULL,
    candidacy_right_active boolean DEFAULT true NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    deactivated_at timestamp(0) without time zone,
    deactivation_reason character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    claim_id uuid,
    depth smallint
);


--
-- Name: restoration_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.restoration_events (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    condition character varying(16) NOT NULL,
    evidence jsonb DEFAULT '{}'::jsonb NOT NULL,
    review_case_id uuid,
    judicially_confirmed boolean DEFAULT false NOT NULL,
    tier smallint,
    tier_election_id uuid,
    status character varying(16) DEFAULT 'declared'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT restoration_events_condition_check CHECK (((condition)::text = ANY ((ARRAY['countermanded'::character varying, 'captured'::character varying, 'destroyed'::character varying])::text[]))),
    CONSTRAINT restoration_events_status_check CHECK (((status)::text = ANY ((ARRAY['declared'::character varying, 'confirmed'::character varying, 'restoring'::character varying, 'restored'::character varying, 'abandoned'::character varying])::text[]))),
    CONSTRAINT restoration_events_tier_check CHECK (((tier IS NULL) OR (tier = ANY (ARRAY[1, 2, 3]))))
);


--
-- Name: sentencing_orders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sentencing_orders (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    case_id uuid NOT NULL,
    verdict_id uuid NOT NULL,
    issued_by_seat_id uuid NOT NULL,
    terms text NOT NULL,
    effective_at timestamp(0) with time zone,
    expires_at timestamp(0) with time zone,
    status character varying(12) NOT NULL,
    record_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT sentencing_orders_status_check CHECK (((status)::text = ANY ((ARRAY['issued'::character varying, 'stayed'::character varying, 'vacated'::character varying, 'completed'::character varying])::text[])))
);


--
-- Name: session_attendance; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.session_attendance (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    session_id uuid NOT NULL,
    member_id uuid NOT NULL,
    status character varying(12) DEFAULT 'absent'::character varying NOT NULL,
    recorded_via_form character varying(12),
    recorded_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT session_attendance_status_check CHECK (((status)::text = ANY ((ARRAY['present'::character varying, 'absent'::character varying, 'compelled'::character varying, 'excused'::character varying])::text[])))
);


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id uuid,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: setting_changes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.setting_changes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    legislature_id uuid,
    setting_key character varying(255) NOT NULL,
    old_value jsonb,
    new_value jsonb NOT NULL,
    law_id uuid NOT NULL,
    applied_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: social_follows; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.social_follows (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    follower_user_id uuid NOT NULL,
    target_type character varying(20) NOT NULL,
    target_id uuid NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT social_follows_target_type_check CHECK (((target_type)::text = ANY ((ARRAY['user'::character varying, 'space'::character varying, 'subforum'::character varying])::text[])))
);


--
-- Name: social_memberships; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.social_memberships (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    space_id uuid NOT NULL,
    user_id uuid NOT NULL,
    role character varying(16) DEFAULT 'member'::character varying NOT NULL,
    block_user_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT social_memberships_role_check CHECK (((role)::text = ANY ((ARRAY['member'::character varying, 'owner'::character varying])::text[])))
);


--
-- Name: social_posts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.social_posts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    thread_id uuid NOT NULL,
    author_user_id uuid NOT NULL,
    author_display character varying(120) NOT NULL,
    body text NOT NULL,
    is_official boolean DEFAULT false NOT NULL,
    acting_seat character varying(40),
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT social_posts_acting_seat_check CHECK (((acting_seat IS NULL) OR ((acting_seat)::text = ANY ((ARRAY['legislature_member'::character varying, 'committee_seat'::character varying, 'exec_seat'::character varying, 'judicial_seat'::character varying])::text[]))))
);


--
-- Name: social_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.social_profiles (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    user_id uuid NOT NULL,
    handle character varying(64),
    display_name character varying(120),
    bio text,
    visibility character varying(12) DEFAULT 'public'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT social_profiles_visibility_check CHECK (((visibility)::text = ANY ((ARRAY['public'::character varying, 'jurisdiction'::character varying, 'private'::character varying])::text[])))
);


--
-- Name: social_reactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.social_reactions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    post_id uuid NOT NULL,
    user_id uuid NOT NULL,
    kind character varying(16) NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT social_reactions_kind_check CHECK (((kind)::text = ANY ((ARRAY['up'::character varying, 'heart'::character varying, 'insightful'::character varying, 'flag'::character varying])::text[])))
);


--
-- Name: social_spaces; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.social_spaces (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    jurisdiction_id uuid NOT NULL,
    space_type character varying(16) NOT NULL,
    title character varying(200) NOT NULL,
    slug character varying(120),
    status character varying(12) DEFAULT 'open'::character varying NOT NULL,
    is_private boolean DEFAULT false NOT NULL,
    owner_org_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    owner_user_id uuid,
    CONSTRAINT social_spaces_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'archived'::character varying])::text[]))),
    CONSTRAINT social_spaces_type_check CHECK (((space_type)::text = ANY ((ARRAY['public_square'::character varying, 'halls'::character varying, 'group'::character varying])::text[])))
);


--
-- Name: social_subforums; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.social_subforums (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    space_id uuid NOT NULL,
    governing_object_type character varying(40),
    governing_object_id uuid,
    title character varying(200) NOT NULL,
    status character varying(12) DEFAULT 'open'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT social_subforums_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'archived'::character varying])::text[])))
);


--
-- Name: social_threads; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.social_threads (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    subforum_id uuid NOT NULL,
    author_user_id uuid NOT NULL,
    author_display character varying(120) NOT NULL,
    title character varying(300) NOT NULL,
    status character varying(12) DEFAULT 'open'::character varying NOT NULL,
    published_record_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT social_threads_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'archived'::character varying])::text[])))
);


--
-- Name: standing_attestations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.standing_attestations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    subject_user_id uuid NOT NULL,
    device_public_key text NOT NULL,
    issuer_server_id uuid NOT NULL,
    roles jsonb DEFAULT '[]'::jsonb NOT NULL,
    issued_at timestamp(0) with time zone NOT NULL,
    expires_at timestamp(0) with time zone NOT NULL,
    signature text NOT NULL,
    source_server_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone
);


--
-- Name: support_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.support_reports (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    public_id character varying(32) NOT NULL,
    category character varying(32) NOT NULL,
    body text NOT NULL,
    ref character varying(300),
    reporter_id uuid,
    status character varying(32) DEFAULT 'open'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone
);


--
-- Name: sync_cursors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sync_cursors (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    peer_id uuid NOT NULL,
    direction character varying(8) DEFAULT 'inbound'::character varying NOT NULL,
    mode character varying(12) DEFAULT 'cold'::character varying NOT NULL,
    anchor_seq bigint,
    from_seq bigint DEFAULT '0'::bigint NOT NULL,
    next_from_seq bigint DEFAULT '0'::bigint NOT NULL,
    page_size integer DEFAULT 500 NOT NULL,
    pages_applied integer DEFAULT 0 NOT NULL,
    records_applied integer DEFAULT 0 NOT NULL,
    last_page_hash character(64),
    status character varying(12) DEFAULT 'open'::character varying NOT NULL,
    abort_reason text,
    detail jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT sync_cursors_direction_check CHECK (((direction)::text = ANY ((ARRAY['inbound'::character varying, 'outbound'::character varying])::text[]))),
    CONSTRAINT sync_cursors_mode_check CHECK (((mode)::text = ANY ((ARRAY['cold'::character varying, 'incremental'::character varying])::text[]))),
    CONSTRAINT sync_cursors_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'paused'::character varying, 'complete'::character varying, 'aborted'::character varying])::text[])))
);


--
-- Name: sync_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sync_log (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    peer_id uuid,
    direction character varying(8) NOT NULL,
    payload_hash character(64) NOT NULL,
    peer_head_hash character(64),
    from_seq bigint,
    to_seq bigint,
    result character varying(32) NOT NULL,
    audit_seq bigint,
    detail jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    seq bigint NOT NULL,
    CONSTRAINT sync_log_direction_check CHECK (((direction)::text = ANY ((ARRAY['inbound'::character varying, 'outbound'::character varying])::text[]))),
    CONSTRAINT sync_log_result_check CHECK (((result)::text = ANY ((ARRAY['applied'::character varying, 'conflict_authoritative_wins'::character varying, 'rejected_tamper'::character varying, 'rejected_non_authoritative'::character varying])::text[])))
);


--
-- Name: sync_log_seq_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sync_log_seq_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sync_log_seq_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sync_log_seq_seq OWNED BY public.sync_log.seq;


--
-- Name: tabulation_rounds; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tabulation_rounds (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tabulation_id uuid NOT NULL,
    round_no smallint NOT NULL,
    action character varying(12) NOT NULL,
    candidacy_id uuid NOT NULL,
    transfer jsonb,
    tallies jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT tabulation_rounds_action_check CHECK (((action)::text = ANY ((ARRAY['elect'::character varying, 'eliminate'::character varying])::text[])))
);


--
-- Name: tabulations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tabulations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    race_id uuid NOT NULL,
    kind character varying(12) DEFAULT 'initial'::character varying NOT NULL,
    excluded_candidacy_id uuid,
    engine_version character varying(32) NOT NULL,
    total_valid integer,
    quota integer,
    seats smallint NOT NULL,
    status character varying(12) DEFAULT 'running'::character varying NOT NULL,
    started_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    completed_at timestamp(0) with time zone,
    record_hash character(64),
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT tabulations_countback_exclusion_check CHECK (((((kind)::text = 'countback'::text) AND (excluded_candidacy_id IS NOT NULL)) OR (((kind)::text <> 'countback'::text) AND (excluded_candidacy_id IS NULL)))),
    CONSTRAINT tabulations_kind_check CHECK (((kind)::text = ANY ((ARRAY['initial'::character varying, 'audit_rerun'::character varying, 'countback'::character varying])::text[]))),
    CONSTRAINT tabulations_status_check CHECK (((status)::text = ANY ((ARRAY['running'::character varying, 'complete'::character varying, 'superseded'::character varying])::text[])))
);


--
-- Name: terms; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.terms (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    office_kind character varying(24) NOT NULL,
    office_type character varying(64),
    office_id uuid,
    holder_user_id uuid NOT NULL,
    jurisdiction_id uuid NOT NULL,
    legislature_id uuid,
    term_class character varying(20) NOT NULL,
    starts_on date NOT NULL,
    ends_on date NOT NULL,
    source_election_id uuid,
    source_appointment_id uuid,
    status character varying(12) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT terms_office_kind_check CHECK (((office_kind)::text = ANY ((ARRAY['legislature_seat'::character varying, 'executive_seat'::character varying, 'judicial_seat'::character varying, 'election_board_member'::character varying, 'board_governor'::character varying, 'board_seat'::character varying, 'admin_staff'::character varying, 'civil_officer'::character varying])::text[]))),
    CONSTRAINT terms_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'completed'::character varying, 'vacated'::character varying, 'removed'::character varying])::text[]))),
    CONSTRAINT terms_term_class_check CHECK (((term_class)::text = ANY ((ARRAY['lockstep'::character varying, 'civil_appointment'::character varying, 'org_cycle'::character varying])::text[])))
);


--
-- Name: union_processes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.union_processes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    kind character varying(12) NOT NULL,
    applicant_jurisdiction_ids jsonb DEFAULT '[]'::jsonb NOT NULL,
    union_jurisdiction_id uuid,
    compatibility_diff jsonb DEFAULT '{}'::jsonb NOT NULL,
    codified_variables jsonb DEFAULT '{}'::jsonb NOT NULL,
    applicant_referendum_election_id uuid,
    applicant_supermajority_met boolean DEFAULT false NOT NULL,
    constituent_process_id uuid,
    status character varying(16) DEFAULT 'open'::character varying NOT NULL,
    resulting_jurisdiction_id uuid,
    initiating_legislature_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT union_processes_kind_check CHECK (((kind)::text = ANY ((ARRAY['formation'::character varying, 'join'::character varying, 'exit'::character varying])::text[]))),
    CONSTRAINT union_processes_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'passed'::character varying, 'failed'::character varying, 'expired'::character varying])::text[])))
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    name character varying(255) NOT NULL,
    display_name character varying(255),
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) with time zone,
    password character varying(255) NOT NULL,
    status character varying(24) DEFAULT 'registered'::character varying NOT NULL,
    identity_verified_at timestamp(0) with time zone,
    identity_verified_via character varying(16),
    terms_accepted_at timestamp(0) with time zone NOT NULL,
    languages jsonb DEFAULT '["en"]'::jsonb NOT NULL,
    timezone character varying(255) DEFAULT 'UTC'::character varying NOT NULL,
    locale character varying(12) DEFAULT 'en'::character varying NOT NULL,
    comm_prefs jsonb DEFAULT '{}'::jsonb NOT NULL,
    home_server_id uuid,
    is_operator boolean DEFAULT false NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    invited_by_user_id uuid,
    CONSTRAINT users_identity_verified_via_check CHECK (((identity_verified_via IS NULL) OR ((identity_verified_via)::text = ANY ((ARRAY['bridge'::character varying, 'attestation'::character varying])::text[])))),
    CONSTRAINT users_status_check CHECK (((status)::text = ANY ((ARRAY['registered'::character varying, 'identity_verified'::character varying, 'deceased'::character varying, 'closed'::character varying])::text[])))
);


--
-- Name: vacancies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vacancies (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    seat_type character varying(64) NOT NULL,
    seat_id uuid NOT NULL,
    legislature_id uuid NOT NULL,
    jurisdiction_id uuid NOT NULL,
    declared_by uuid,
    declared_via_form character varying(16),
    status character varying(32) DEFAULT 'detected'::character varying NOT NULL,
    detected_at timestamp(0) with time zone NOT NULL,
    declared_at timestamp(0) with time zone,
    countback_tabulation_id uuid,
    special_election_id uuid,
    filled_by_user_id uuid,
    filled_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT vacancies_status_check CHECK (((status)::text = ANY ((ARRAY['detected'::character varying, 'declared'::character varying, 'countback_running'::character varying, 'filled'::character varying, 'countback_failed'::character varying, 'special_election_scheduled'::character varying])::text[])))
);


--
-- Name: verdicts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.verdicts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    case_id uuid NOT NULL,
    decided_by character varying(12) NOT NULL,
    outcome character varying(20) NOT NULL,
    panel_vote_for smallint,
    panel_vote_against smallint,
    jury_unanimous boolean,
    summary text,
    double_jeopardy_flag boolean DEFAULT false NOT NULL,
    record_id uuid,
    decided_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT verdicts_decided_by_check CHECK (((decided_by)::text = ANY ((ARRAY['panel'::character varying, 'jury'::character varying])::text[]))),
    CONSTRAINT verdicts_outcome_check CHECK (((outcome)::text = ANY ((ARRAY['guilty'::character varying, 'not_guilty'::character varying, 'liable'::character varying, 'not_liable'::character varying, 'dismissed'::character varying, 'for_petitioner'::character varying, 'for_respondent'::character varying])::text[])))
);


--
-- Name: vote_casts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vote_casts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    vote_id uuid NOT NULL,
    member_id uuid,
    lane character varying(8) NOT NULL,
    value character varying(8),
    rankings jsonb,
    is_tiebreak boolean DEFAULT false NOT NULL,
    explanation text,
    cast_via_form character varying(12) NOT NULL,
    public_record_id uuid,
    cast_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    board_seat_id uuid,
    CONSTRAINT vote_casts_caster_xor CHECK (((member_id IS NOT NULL) <> (board_seat_id IS NOT NULL))),
    CONSTRAINT vote_casts_lane_check CHECK (((lane)::text = ANY ((ARRAY['all'::character varying, 'type_a'::character varying, 'type_b'::character varying])::text[]))),
    CONSTRAINT vote_casts_value_check CHECK (((value IS NULL) OR ((value)::text = ANY ((ARRAY['yes'::character varying, 'no'::character varying, 'abstain'::character varying])::text[])))),
    CONSTRAINT vote_casts_value_xor_rankings CHECK (((value IS NOT NULL) <> (rankings IS NOT NULL)))
);


--
-- Name: warrants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.warrants (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    case_id uuid NOT NULL,
    issued_by_seat_id uuid NOT NULL,
    kind character varying(12) NOT NULL,
    stated_reason text NOT NULL,
    max_hold_duration_hours integer,
    subject_user_id uuid,
    status character varying(12) NOT NULL,
    issued_at timestamp(0) with time zone,
    executed_at timestamp(0) with time zone,
    expires_at timestamp(0) with time zone,
    record_id uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    CONSTRAINT warrants_kind_check CHECK (((kind)::text = ANY ((ARRAY['arrest'::character varying, 'search'::character varying, 'seizure'::character varying])::text[]))),
    CONSTRAINT warrants_max_hold_positive CHECK (((max_hold_duration_hours IS NULL) OR (max_hold_duration_hours > 0))),
    CONSTRAINT warrants_stated_reason_present CHECK ((btrim(stated_reason) <> ''::text)),
    CONSTRAINT warrants_status_check CHECK (((status)::text = ANY ((ARRAY['issued'::character varying, 'executed'::character varying, 'expired'::character varying, 'quashed'::character varying])::text[])))
);


--
-- Name: worldpop_rasters; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.worldpop_rasters (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    iso_code character varying(3) NOT NULL,
    year smallint DEFAULT 2023 NOT NULL,
    resolution_m smallint DEFAULT 100 NOT NULL,
    rast public.raster NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: audit_checkpoints seq; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_checkpoints ALTER COLUMN seq SET DEFAULT nextval('public.audit_checkpoints_seq_seq'::regclass);


--
-- Name: audit_log seq; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_log ALTER COLUMN seq SET DEFAULT nextval('public.audit_log_seq_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: sync_log seq; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sync_log ALTER COLUMN seq SET DEFAULT nextval('public.sync_log_seq_seq'::regclass);


--
-- Data for Name: achievements; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.achievements (id, user_id, journey_id, title, source_server_id, audit_seq, earned_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: actor_devices; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.actor_devices (id, user_id, device_public_key, label, enrolled_at, revoked_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: admin_offices; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.admin_offices (id, legislature_id, created_by_vote_id, created_by_law_id, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: advocates; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.advocates (id, user_id, judiciary_id, jurisdiction_id, status, qualifications_note, registered_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: appointments; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.appointments (id, appointable_type, appointable_id, nominee_user_id, nominated_by, nominated_via_form, consent_vote_id, status, term_id, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: appropriations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.appropriations (id, law_id, jurisdiction_id, executive_id, line, amount, remaining, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: approval_standings; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.approval_standings (id, race_id, candidacy_id, as_of_date, approvals_count, rank, delta, is_frozen, created_at) FROM stdin;
\.


--
-- Data for Name: approvals; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.approvals (id, election_id, candidacy_id, user_id, created_at, revoked_at) FROM stdin;
\.


--
-- Data for Name: attestation_revocations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.attestation_revocations (id, attestation_id, issuer_server_id, reason, revoked_at, signature, source_server_id, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: audit_chain_reconciliations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.audit_chain_reconciliations (id, break_seq, observed_prev_hash, expected_prev_hash, reason, authority_kind, acknowledged_by_user_id, acknowledged_by_operator_id, consent, audit_seq, acknowledged_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: audit_checkpoints; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.audit_checkpoints (id, audit_seq, head_hash, published_to, signature, created_at, seq) FROM stdin;
\.


--
-- Data for Name: audit_log; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.audit_log (id, occurred_at, actor_user_id, module, event, ref, jurisdiction_id, payload, prev_hash, hash, rejected, blocked_reason, created_at, seq) FROM stdin;
bd804e7e-2214-478e-bdc0-b959f59c8bf2	2026-07-05 15:34:09+00	\N	system	genesis	WF-SYS-04	\N	{"note": "Cosmopolitan Governance App audit chain genesis", "genesis": true, "algorithm": "sha256(prev_hash || canonical_json(payload))"}	0000000000000000000000000000000000000000000000000000000000000000	f1ee792f1f3505ae32896c8c0ef2fc097e8bed2b3ed044459810b63afa7cca33	f	\N	2026-07-05 15:34:09+00	1
\.


--
-- Data for Name: authority_claims; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.authority_claims (id, jurisdiction_id, claimed_by_peer_id, resolution, authority_flipped_at, partition_export_id, notes, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: ballot_envelopes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.ballot_envelopes (id, race_id, user_id, kind, referendum_question_id, committed_at, created_at) FROM stdin;
\.


--
-- Data for Name: ballots; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.ballots (id, race_id, kind, payload_encrypted, salt, ballot_hash, cast_bucket, counted, referendum_question_id) FROM stdin;
\.


--
-- Data for Name: bill_versions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.bill_versions (id, bill_id, version_no, law_text, changed_by_member_id, change_kind, created_at) FROM stdin;
\.


--
-- Data for Name: bills; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.bills (id, legislature_id, jurisdiction_id, sponsor_member_id, title, act_type, scale, scope_judiciary_id, targets_setting_key, proposed_value, effective_at, status, committee_id, current_version_no, introduced_at, passed_at, failed_at, enacted_at, enacted_law_id, created_at, updated_at, deleted_at, targets_challenge_id) FROM stdin;
\.


--
-- Data for Name: board_seats; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.board_seats (id, board_id, seat_class, seat_no, holder_user_id, appointment_id, elected_in_race_id, term_id, is_chair, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: boards; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.boards (id, boardable_type, boardable_id, owner_seats, worker_seats, worker_headcount, chair_seat_id, composition_valid, cycle_months, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: border_settlements; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.border_settlements (id, jurisdiction_a_id, jurisdiction_b_id, affected_jurisdiction_ids, affected_population, referendum_election_id, affected_supermajority_met, jurisdiction_map_id, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: broker_authorizations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.broker_authorizations (id, domain, broker_server_id, authority_server_id, authority_pubkey, signature, issued_at, revoked_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: cache; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.cache (key, value, expiration) FROM stdin;
\.


--
-- Data for Name: cache_locks; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.cache_locks (key, owner, expiration) FROM stdin;
\.


--
-- Data for Name: candidacies; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.candidacies (id, election_id, race_id, user_id, status, platform_statement, position_tags, residency_attested_at, validated_at, validated_by_member_id, rejection_reason, withdrawn_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: case_filings; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.case_filings (seq, id, case_id, filing_form, filing_kind, filed_by_user_id, filed_by_role, advocate_id, title, body, ruling, ruling_reason, accepted_at_state, record_id, audit_seq, created_at) FROM stdin;
\.


--
-- Data for Name: case_parties; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.case_parties (id, case_id, party_role, party_type, party_user_id, party_ref_type, party_ref_id, represented_by_advocate_id, retainer_note, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: cases; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.cases (id, docket_no, judiciary_id, jurisdiction_id, kind, title, statement_of_claim, claimed_severity, court_severity, jury_entitled, jury_waived, filed_via_form, filed_by_user_id, filed_on_behalf_of_user_id, advocate_id, panel_id, jury_id, appeal_of_case_id, status, double_jeopardy_locked, accepted_at, decided_at, closed_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: cgc_ip_register; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.cgc_ip_register (seq, id, organization_id, asset, kind, description, status, dedicated_via_form, dedicated_by_user_id, published_record_id, audit_seq, published_at, created_at) FROM stdin;
\.


--
-- Data for Name: chamber_vote_proposals; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.chamber_vote_proposals (id, legislature_id, proposal_kind, vote_id, payload, proposed_by_member_id, status, decided_at, result_type, result_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: chamber_vote_tallies; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.chamber_vote_tallies (id, vote_id, lane, serving, quorum_required, required_yes, present, yes, no, abstain, quorate, passed) FROM stdin;
\.


--
-- Data for Name: chamber_votes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.chamber_votes (id, body_type, body_id, legislature_id, jurisdiction_id, votable_type, votable_id, vote_type, vote_method, threshold_basis, stage, bicameral, serving_snapshot, held_in_session_id, opened_by_member_id, opened_at, closes_at, decided_at, outcome, speaker_tiebreak, rcv_record, status, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: clock_timers; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.clock_timers (id, clock_id, jurisdiction_id, subject_type, subject_id, armed_at, fires_at, state, payload, override_value, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: clocks; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.clocks (id, name, type, default_value, amendable, fires_workflow, basis, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: cluster_adoption_requests; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.cluster_adoption_requests (id, applicant_server_id, applicant_public_key, nonce, admission_method, status, join_key_handle, cluster_membership_id, created_at, updated_at, deleted_at, requested_relation, requested_scope_jurisdiction_id, applicant_name, applicant_url, note) FROM stdin;
\.


--
-- Data for Name: cluster_join_keys; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.cluster_join_keys (id, handle, key_hash, max_uses, uses, scope_jurisdiction_id, expires_at, revoked_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: cluster_members; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.cluster_members (id, cluster_id, server_id, is_self, state, role, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: cluster_memberships; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.cluster_memberships (id, peer_id, role, state, admission_method, scope_jurisdiction_id, backfill_cursor_seq, backfill_target_seq, backfilled_at, created_at, updated_at, deleted_at, seed_dataset, seed_version, seed_sha256, seed_total_bytes, seed_cursor_bytes, seeded_at) FROM stdin;
\.


--
-- Data for Name: clusters; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.clusters (id, name, kind, jurisdiction_id, authority_claim_id, is_self, leader_server_id, leader_epoch, topology, dcs_backend, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: committee_meetings; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.committee_meetings (id, committee_id, called_by_member_id, scheduled_for, agenda, opened_at, adjourned_at, status, minutes_record_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: committee_preferences; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.committee_preferences (id, legislature_id, member_id, rankings, submitted_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: committee_reports; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.committee_reports (id, committee_id, bill_id, filed_by_member_id, report_record_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: committee_seats; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.committee_seats (id, committee_id, member_id, seat_kind, status, assigned_via, preference_rank_honored, seated_at, vacated_at, vacated_reason, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: committees; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.committees (id, legislature_id, name, purpose, seats, type_a_seats, type_b_seats, created_by_vote_id, created_by_law_id, chair_member_id, alternate_member_id, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: constituent_consents; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.constituent_consents (id, process_id, jurisdiction_id, legislature_id, chamber_vote_id, result, decided_at) FROM stdin;
\.


--
-- Data for Name: constitutional_challenges; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.constitutional_challenges (id, jurisdiction_id, judiciary_id, challenged_law_id, challenged_version_no, filed_by_user_id, claim_text, claimed_basis, cited_authority_law_id, constitutional_citation, case_id, status, finding_id, remedy_id, resolution_path, resolution_ref_type, resolution_ref_id, filed_at, heard_at, finding_at, closed_at, record_id, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: constitutional_findings; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.constitutional_findings (id, challenge_id, judiciary_id, case_id, full_court, finds_contradiction, contradiction_against, superior_authority_law_id, constitutional_citation, offending_law_id, offending_version_no, opinion_text, panel_snapshot, record_id, issued_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: constitutional_settings; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.constitutional_settings (id, jurisdiction_id, election_interval_months, voting_method, special_election_min_days, special_election_max_days, legislature_min_seats, legislature_max_seats, supermajority_numerator, supermajority_denominator, max_days_between_meetings, emergency_powers_max_days, civil_appointment_years, judicial_appointment_years, judiciary_min_judges_per_race, judiciary_is_elected, worker_rep_min_employees, worker_rep_parity_employees, residency_confirmation_days, initiative_petition_threshold_pct, last_amended_by_act_id, last_amended_at, created_at, updated_at, type_b_seats_per_child, legislature_sizing_law, critical_population_threshold, finalist_multiplier, ranked_window_days, approval_min_days, last_amendment_route, last_amendment_process_id) FROM stdin;
\.


--
-- Data for Name: cosmic_addresses; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.cosmic_addresses (id, parent_id, label, slug, type, subtype, enabled, source, sort_order, metadata, created_at, updated_at, deleted_at) FROM stdin;
73abe250-4344-4f96-b733-1e31dc0ac184	\N	Multiverse	multiverse	multiverse	\N	t	seed	0	\N	2026-07-05 15:34:08	2026-07-05 15:34:08	\N
ff044482-2bcf-4879-99d7-d76c37da6cec	73abe250-4344-4f96-b733-1e31dc0ac184	Observable Universe	observable-universe	observable_universe	\N	t	seed	0	\N	2026-07-05 15:34:08	2026-07-05 15:34:08	\N
ebe76618-2a8c-4cc5-b2a2-ac21f3dcb48e	ff044482-2bcf-4879-99d7-d76c37da6cec	Laniakea Supercluster	laniakea-supercluster	supercluster	\N	t	seed	0	\N	2026-07-05 15:34:08	2026-07-05 15:34:08	\N
063adbdd-b3a1-40db-953a-80a4da951dad	ebe76618-2a8c-4cc5-b2a2-ac21f3dcb48e	Local Group	local-group	galaxy_group	\N	t	seed	0	\N	2026-07-05 15:34:08	2026-07-05 15:34:08	\N
07bee891-15a0-4285-9482-7f65065103b2	063adbdd-b3a1-40db-953a-80a4da951dad	Milky Way	milky-way	galaxy	\N	t	seed	0	\N	2026-07-05 15:34:08	2026-07-05 15:34:08	\N
ea378b1b-9565-4909-9ae1-e57dc950bf6d	07bee891-15a0-4285-9482-7f65065103b2	Orion Arm (Orion Spur)	orion-arm	galactic_region	\N	t	seed	0	\N	2026-07-05 15:34:08	2026-07-05 15:34:08	\N
08352947-1d97-49a2-ad49-4173617d9db9	ea378b1b-9565-4909-9ae1-e57dc950bf6d	Solar System	solar-system	star_system	\N	t	seed	0	\N	2026-07-05 15:34:08	2026-07-05 15:34:08	\N
cbde2e60-8764-480b-a7d0-e091494d60d4	08352947-1d97-49a2-ad49-4173617d9db9	Earth	earth	world	planet	t	seed	0	\N	2026-07-05 15:34:08	2026-07-05 15:34:08	\N
6e751093-5b38-4df6-b5f0-136220ca6f8a	73abe250-4344-4f96-b733-1e31dc0ac184	No Map	no-map	no_map	\N	f	seed	10	\N	2026-07-05 15:34:08	2026-07-05 15:34:08	\N
a43eff96-a525-4997-8d1b-ffa7751a6eae	73abe250-4344-4f96-b733-1e31dc0ac184	Custom Universe	custom-universe	custom_universe	\N	f	seed	5	\N	2026-07-05 15:34:08	2026-07-05 15:34:08	\N
\.


--
-- Data for Name: cultural_institutions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.cultural_institutions (id, jurisdiction_id, legislature_id, name, description, recognition_vote_id, status, record_id, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: data_review_decisions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.data_review_decisions (id, category, jurisdiction_id, decision, note, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: department_reports; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.department_reports (id, department_id, kind, period_label, due_on, filed_at, filed_by_seat_id, recipients, record_id, status, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: department_rules; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.department_rules (id, department_id, rule_code, name, text, enabling_type, enabling_id, expires_with_enabling, version_no, supersedes_rule_id, filed_by_seat_id, record_id, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: departments; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.departments (id, jurisdiction_id, executive_id, kind, name, charter_law_id, reporting_interval_months, board_id, worker_count, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: directory_entries; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.directory_entries (id, jurisdiction_id, server_id, endpoints, priority, signature, source_server_id, published_at, expires_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: disintermediation_processes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.disintermediation_processes (id, intermediary_jurisdiction_id, encompassing_jurisdiction_id, constituent_process_id, encompassing_consent, encompassing_consent_vote_id, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: district_subdivisions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.district_subdivisions (id, map_id, parent_jurisdiction_id, parent_subdivision_id, method, label, population, population_source, population_year, fractional_seats, seats, status, created_at, updated_at, deleted_at, geom, centroid) FROM stdin;
\.


--
-- Data for Name: election_audits; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.election_audits (id, election_id, race_id, cause, ordered_by, ordered_at, tabulation_id, outcome, resolved_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: election_ballot_key_rewraps; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.election_ballot_key_rewraps (id, election_id, jurisdiction_id, from_cluster_id, to_cluster_id, prior_wrap_fingerprint, new_wrap_fingerprint, races_verified, count_record_digest, verified_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: election_board_members; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.election_board_members (id, election_board_id, user_id, appointment_id, status, term_starts_on, term_ends_on, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: election_boards; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.election_boards (id, jurisdiction_id, legislature_id, created_by_act_id, is_bootstrap, status, retired_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: election_certifications; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.election_certifications (id, election_id, election_board_id, certified_by_member_id, certified_at, count_record_hash, status, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: election_races; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.election_races (id, election_id, district_id, jurisdiction_id, seat_kind, seats, finalist_count, electorate_type, quota, total_valid_ballots, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: elections; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.elections (id, jurisdiction_id, kind, voting_method, status, trigger, election_board_id, created_at, updated_at, deleted_at, legislature_id, district_map_id, approval_opens_at, finalist_cutoff_at, ranked_opens_at, ranked_closes_at, certified_at, prior_election_id, triggered_by_timer_id, vacancy_id, ballot_key_wrapped, executive_id, board_id, judiciary_id, constitutional_version) FROM stdin;
\.


--
-- Data for Name: emergency_power_renewals; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.emergency_power_renewals (id, emergency_power_id, vote_id, extension_days, previous_expires_at, new_expires_at, created_at) FROM stdin;
\.


--
-- Data for Name: emergency_power_reviews; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.emergency_power_reviews (id, emergency_power_id, judiciary_id, case_id, challenge_id, review_basis, outcome, narrowed_area_jurisdiction_id, narrowed_methods, opinion_text, record_id, issued_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: emergency_powers; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.emergency_powers (id, legislature_id, jurisdiction_id, cause, label, declared_duration_days, area_jurisdiction_id, methods, invoke_vote_id, status, starts_at, expires_at, judicial_review_case_id, review_outcome, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: endorsement_requests; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.endorsement_requests (id, candidacy_id, organization_id, message, status, requested_at, decided_at, endorsement_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: endorsements; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.endorsements (id, election_id, candidate_id, endorser_type, endorser_id, statement, endorsed_at, withdrawn_at, is_active, created_at, updated_at, is_public) FROM stdin;
\.


--
-- Data for Name: executive_investigations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.executive_investigations (id, executive_id, department_id, ordered_by_member_id, scope, records_access, findings_record_id, outcome, outcome_ref_type, outcome_ref_id, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: executive_members; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.executive_members (id, executive_id, user_id, role, rank, joined_at, left_at, created_at, updated_at, deleted_at, legislature_member_id, elected_in_race_id, term_id, selection, status) FROM stdin;
\.


--
-- Data for Name: executive_orders; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.executive_orders (id, executive_id, issued_by_member_id, department_id, order_no, title, body, enabling_type, enabling_id, target_domain, status, rejection_citation, rejection_reason, record_id, judicial_review_case_id, issued_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: executives; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.executives (id, jurisdiction_id, type, term_number, term_starts_on, term_ends_on, status, parent_executive_id, source_legislature_id, created_at, updated_at, deleted_at, delegation_law_id, delegated_scope, conversion_process_id, conversion_law_id, converted_at, delegated_member_count) FROM stdin;
\.


--
-- Data for Name: failed_jobs; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.failed_jobs (id, uuid, connection, queue, payload, exception, failed_at) FROM stdin;
\.


--
-- Data for Name: federation_peers; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.federation_peers (id, server_id, name, url, public_key, status, metadata, last_heartbeat_at, trust_established_at, last_synced_seq, peer_head_seq, created_at, updated_at, deleted_at, relation, constitutional_version, app_release) FROM stdin;
\.


--
-- Data for Name: federation_transport_health; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.federation_transport_health (id, server_id, transport, url, last_ok_at, last_fail_at, consecutive_failures, latency_ema_ms, circuit_state, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: federation_transports; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.federation_transports (id, server_id, transport, address, is_self, priority, enabled, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: finding_offending_laws; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.finding_offending_laws (id, finding_id, law_id, version_no, remedy_recommendation_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: forwarded_writes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.forwarded_writes (id, origin_server_id, idempotency_key, form_id, jurisdiction_id, status, audit_seq, result_hash, citation, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: foundation_sync_cursors; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.foundation_sync_cursors (id, peer_id, table_name, from_key, next_from_key, page_size, pages_applied, rows_applied, total_rows, status, abort_reason, detail, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: geoboundary_metadata; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.geoboundary_metadata (iso_code, adm_level, boundary_id, name, year_represented, boundary_type, boundary_canonical, boundary_source, boundary_license, license_detail, license_source, boundary_source_url, source_data_update_date, build_date, continent, unsdg_region, unsdg_subregion, world_bank_income_group, adm_unit_count, mean_vertices, min_vertices, max_vertices, mean_perimeter_length_km, min_perimeter_length_km, max_perimeter_length_km, mean_area_sq_km, min_area_sq_km, max_area_sq_km, static_download_link, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: geodata_dataset_manifests; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.geodata_dataset_manifests (id, dataset, version, sha256, license, size_bytes, origin_server_id, signature, fetched_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: governor_removal_requests; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.governor_removal_requests (id, board_seat_id, requested_by_member_id, grounds, record_id, vote_id, outcome, decided_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: grant_applications; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.grant_applications (id, appropriation_id, applicant_org_id, amount, purpose, status, decided_by_member_id, decided_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: grant_disbursements; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.grant_disbursements (id, application_id, amount, disbursed_by_member_id, disbursed_at, created_at) FROM stdin;
\.


--
-- Data for Name: instance_capabilities; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.instance_capabilities (id, server_id, capability, is_self, enabled, priority, granted_by_server_id, grant_signature, grant_expires_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: instance_settings; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.instance_settings (id, instance_name, cosmic_address_id, map_mode, time_mode, time_scale_seconds_per_year, setup_step_completed, setup_completed_at, created_at, updated_at, deleted_at, pending_constitutional_defaults, apportionment_completed_at, apportionment_log, setup_districts_confirmed_at, setup_completion_notes, map_accepted_at, server_id, public_key, private_key_encrypted, signing_key_generated_at, federation_enabled, mirror_of_server_id, mirror_adopted_at, attestation_authority_enabled, home_cluster_id, geodata_posture, constitutional_version, app_release, version_pinned_at, setup_mode, infra_overrides) FROM stdin;
019f32eb-3578-70ab-b1fa-44b1a8af0ab3	Unnamed Instance	\N	physical_earth	real	\N	0	\N	2026-07-05 15:35:03	2026-07-05 15:35:03	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	f	\N	\N	f	\N	\N	\N	\N	\N	\N	\N
\.


--
-- Data for Name: invites; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.invites (id, handle, token_hash, inviter_user_id, kind, destination, label, max_uses, uses, expires_at, revoked_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: job_batches; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.job_batches (id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, options, cancelled_at, created_at, finished_at) FROM stdin;
\.


--
-- Data for Name: jobs; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.jobs (id, queue, payload, attempts, reserved_at, available_at, created_at) FROM stdin;
\.


--
-- Data for Name: journey_progress; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.journey_progress (id, user_id, journey_id, steps_done, completed_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: judicial_nominations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.judicial_nominations (id, judiciary_id, seat_id, mode, nominating_jurisdiction_id, nominee_user_id, appointment_id, dossier_record_id, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: judicial_seats; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.judicial_seats (id, judiciary_id, user_id, seat_number, term_starts_on, term_ends_on, status, created_at, updated_at, deleted_at, seat_class, nominating_jurisdiction_id, appointment_id, elected_in_race_id, term_id) FROM stdin;
\.


--
-- Data for Name: judiciaries; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.judiciaries (id, jurisdiction_id, court_name, type, min_judges, term_years, status, parent_judiciary_id, created_at, updated_at, deleted_at, creation_law_id, nomination_mode, conversion_process_id, conversion_law_id, converted_at, judge_count, source_legislature_id) FROM stdin;
\.


--
-- Data for Name: juries; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.juries (id, case_id, selection_order_id, pool_size, eligible_jurisdiction_id, seats, alternates, draw_seed, report_on, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: jurisdiction_activations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.jurisdiction_activations (id, jurisdiction_id, state, critical_population_at, activated_at, legislature_id, notes, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: jurisdiction_maps; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.jurisdiction_maps (id, root_jurisdiction_id, name, description, status, version_no, origin, origin_process_id, effective_start, effective_end, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: jurisdictions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.jurisdictions (id, name, slug, iso_code, adm_level, parent_id, population, population_year, is_active, authoritative_server_id, authoritative_server_url, last_synced_at, source, geoboundaries_id, official_languages, timezone, created_at, updated_at, deleted_at, geom, centroid, is_civic_active, parent_assigned_via, population_assigned_via, population_baseline, map_id, lifecycle_status) FROM stdin;
\.


--
-- Data for Name: jury_members; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.jury_members (id, jury_id, user_id, seat_kind, seat_no, screening_status, excusal_reason, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: law_merge_resolutions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.law_merge_resolutions (id, process_id, law_id, target_jurisdiction_id, decision, resulting_law_id, resolved_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: law_versions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.law_versions (id, law_id, version_no, text, text_hash, source, source_ref_type, source_ref_id, created_at) FROM stdin;
\.


--
-- Data for Name: laws; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.laws (id, jurisdiction_id, legislature_id, act_number, title, kind, scale, scope_judiciary_id, origin, enacting_bill_id, origin_ref_type, origin_ref_id, referendum_passed_by_supermajority, shield_expires_with_election_id, status, current_version_no, effective_at, enacted_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: legal_compliance_removals; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.legal_compliance_removals (id, matrix_event_id, matrix_room_id, operator_account_id, legal_basis, action, statutory_citation, matched_list_source, public_records_id, jurisdiction_id, is_seated_at_time, referral_record_id, created_at, updated_at, physical_removal_status) FROM stdin;
\.


--
-- Data for Name: legislature_district_jurisdictions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.legislature_district_jurisdictions (id, district_id, jurisdiction_id, subdivision_id) FROM stdin;
\.


--
-- Data for Name: legislature_district_maps; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.legislature_district_maps (id, legislature_id, name, description, status, effective_start, effective_end, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: legislature_districts; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.legislature_districts (id, legislature_id, jurisdiction_id, district_number, seats, target_population, actual_population, created_at, updated_at, deleted_at, status, fractional_seats, floor_override, map_id, num_geom_parts, is_contiguous, convex_hull_ratio) FROM stdin;
\.


--
-- Data for Name: legislature_members; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.legislature_members (id, legislature_id, user_id, seat_type, district_id, seated_on, term_ends_on, status, vacated_at, vacancy_reason, election_id, is_speaker, created_at, updated_at, deleted_at, seat_no, elected_in_race_id, term_id, vote_share_norm, seated_at, home_jurisdiction_id) FROM stdin;
\.


--
-- Data for Name: legislature_sessions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.legislature_sessions (id, legislature_id, session_no, called_by_member_id, scheduled_for, opened_at, adjourned_at, serving_at_open, quorum_required, serving_by_kind, quorum_required_by_kind, quorum_met, agenda, minutes_record_id, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: legislatures; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.legislatures (id, jurisdiction_id, term_number, term_starts_on, term_ends_on, status, total_seats, type_a_seats, type_b_seats, speaker_id, quorum_required, last_met_on, next_meeting_due_by, parent_legislature_id, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: local_autonomy_processes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.local_autonomy_processes (id, promoting_jurisdiction_id, promoting_legislature_id, parent_jurisdiction_id, gaining_server_id, gaining_cluster_id, parent_process_id, promoting_supermajority_met, status, resulting_authoritative_server_id, subtree_size, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: location_pings; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.location_pings (id, user_id, latitude, longitude, accuracy_meters, source, pinged_at, created_at, updated_at, geom, claim_id, is_qualifying, evaluated_at) FROM stdin;
\.


--
-- Data for Name: matrix_carveout_log; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.matrix_carveout_log (id, matrix_room_id, matrix_event_id, carve_out, action, attestation_id, issuer_server_id, public_records_id, jurisdiction_id, is_seated_at_time, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: matrix_event_snapshots; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.matrix_event_snapshots (id, matrix_event_id, matrix_room_id, published_record_id, actor_display, origin_server_ts, body_snapshot, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: matrix_identities; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.matrix_identities (id, user_id, matrix_localpart, matrix_user_id, device_master_key, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: matrix_rooms; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.matrix_rooms (id, matrix_room_id, matrix_alias, room_type, room_version, entity_type, entity_id, space_type, is_public, is_seated, is_activated, tombstoned_at, created_at, updated_at, deleted_at, is_encrypted) FROM stdin;
\.


--
-- Data for Name: matrix_server_acls; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.matrix_server_acls (id, matrix_room_id, allow, deny, written_by_carve_out, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: mesh_operator_identities; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.mesh_operator_identities (id, display_handle, genesis_server_id, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: mesh_operator_keys; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.mesh_operator_keys (id, mesh_operator_id, device_public_key, bound_by_server_id, binding_signature, status, bound_at, revoked_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: mesh_operator_local_links; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.mesh_operator_local_links (id, operator_account_id, mesh_operator_id, linked_via_peer_id, linked_at, unlinked_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
\.


--
-- Data for Name: misconduct_investigations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.misconduct_investigations (id, admin_office_id, code, subject_type, subject_id, complainant_user_id, summary, status, findings_record_id, referred_proceeding_id, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: motions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.motions (id, session_id, bill_id, moved_by_member_id, seconded_by_member_id, text, kind, status, vote_id, amendment_text, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: multi_jurisdiction_votes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.multi_jurisdiction_votes (id, kind, subject_type, subject_id, initiating_legislature_id, initiating_vote_id, basis, constituent_total, required, yes_count, no_count, status, opens_at, closes_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: oidc_authorization_codes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.oidc_authorization_codes (id, code_hash, client_id, user_id, redirect_uri, scope, code_challenge, nonce, expires_at, consumed_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: oidc_signing_keys; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.oidc_signing_keys (id, kid, algorithm, public_jwk, private_pem_encrypted, is_active, rotated_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: operational_partition_exports; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.operational_partition_exports (id, root_jurisdiction_id, direction, peer_server_id, election_count, applied_count, sealed_fingerprint, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: operator_accounts; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.operator_accounts (id, server_id, username, password, mesh_operator_id, status, last_login_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: operator_devices; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.operator_devices (id, operator_account_id, device_public_key, label, enrolled_at, revoked_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: opinion_law_links; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.opinion_law_links (id, opinion_id, law_id, law_version_no, relation, note, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: opinions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.opinions (id, case_id, panel_id, authored_by_seat_id, kind, title, body, record_id, published_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: org_contracts; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.org_contracts (id, organization_id, counterparty_type, counterparty_id, kind, terms, signed_by_org_user_id, signed_by_org_at, signed_by_counterparty_at, status, effective_at, ended_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: org_conversions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.org_conversions (id, organization_id, direction, via, proposal_id, authorizing_vote_id, authorizing_law_id, fair_market_floor, fair_market_basis, compensation, compensation_record_id, board_transition, status, completed_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: org_document_package_versions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.org_document_package_versions (id, package_id, version_no, content, created_by_user_id, created_at) FROM stdin;
\.


--
-- Data for Name: org_document_packages; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.org_document_packages (id, organization_id, key, name, kind, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: org_memberships; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.org_memberships (id, organization_id, user_id, kind, status, applied_at, accepted_at, ended_at, accepted_by_user_id, end_reason, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: org_ownership_stakes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.org_ownership_stakes (id, organization_id, holder_type, holder_id, units, pct, acquired_via, source_transfer_id, as_of, ended_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: org_transfers; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.org_transfers (id, organization_id, to_party_type, to_party_id, terms, consent_from_at, consent_from_user_id, consent_to_at, consent_to_user_id, status, completed_at, ffc_synced_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: org_workers; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.org_workers (id, employer_type, employer_id, user_id, contract_id, status, started_at, ended_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: organizations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.organizations (id, jurisdiction_id, type, name, slug, abbreviation, color, description, website_url, parent_organization_id, is_cgc, created_by_legislature_id, overseen_by_executive_id, ownership_type, worker_count, ip_is_public_domain, is_active, is_registered, registered_at, dissolved_at, dissolution_reason, created_at, updated_at, deleted_at, agent_user_id, structure, status, registered_by_user_id, registered_via_form, purpose, created_by_law_id, board_id, registration_record_id) FROM stdin;
\.


--
-- Data for Name: panel_judges; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.panel_judges (id, panel_id, judicial_seat_id, user_id, is_presiding, screening_result, recusal_reason, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: panels; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.panels (id, case_id, judiciary_id, size, is_en_banc, severity_basis, presiding_judge_seat_id, draw_seed, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: partition_exports; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.partition_exports (id, jurisdiction_id, direction, peer_id, manifest, checksum, checkpoint_audit_seq, signed_by, signature, status, authority_flipped_at, error, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: password_reset_tokens; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.password_reset_tokens (email, token, created_at) FROM stdin;
\.


--
-- Data for Name: peer_upgrade_consents; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.peer_upgrade_consents (id, proposal_id, meter, operator_account_id, mesh_operator_id, peer_server_id, mjv_process_id, result, signature, decided_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: peer_upgrade_proposals; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.peer_upgrade_proposals (id, kind, from_constitutional_version, to_constitutional_version, from_schema_version, to_schema_version, from_app_release, to_app_release, hardened_params, affected_root_jurisdiction_id, proposed_by_server_id, signature, status, seated_process_id, ratified_at, created_at, updated_at, deleted_at, capability, grant_payload) FROM stdin;
\.


--
-- Data for Name: petition_signatures; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.petition_signatures (id, petition_id, user_id, association_id, signed_at, revoked_at) FROM stdin;
\.


--
-- Data for Name: petitions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.petitions (id, creator_user_id, jurisdiction_id, title, law_text, act_type, targets_setting_key, proposed_value, scale, scope_judiciary_id, population_basis, threshold_pct, threshold_count, status, audit_result, review_case_id, review_stub, referendum_question_id, created_at, updated_at, deleted_at, review_outcome) FROM stdin;
\.


--
-- Data for Name: policy_proposals; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.policy_proposals (id, executive_id, department_id, proposed_by_member_id, title, text, board_vote_id, decision, amended_text, decided_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: public_records; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.public_records (id, kind, title, body, actor_user_id, actor_display, jurisdiction_id, legislature_id, via_form, via_workflow, via_clock, subject_type, subject_id, audit_seq, translations, supersedes_record_id, published_at, created_at, seq, source_server_id) FROM stdin;
\.


--
-- Data for Name: race_results; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.race_results (id, tabulation_id, candidacy_id, round_elected, seat_no, vote_share_norm, is_runner_up, runner_up_rank, created_at) FROM stdin;
\.


--
-- Data for Name: read_write_requests; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.read_write_requests (id, applicant_server_id, applicant_public_key, root_jurisdiction_id, status, autonomy_process_id, note, submitted_at, resolved_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: referendum_questions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.referendum_questions (id, jurisdiction_id, origin, delegating_vote_id, petition_id, question, law_text, act_type, threshold, targets_setting_key, proposed_value, election_id, eligible_population, yes_count, no_count, status, resulting_law_id, certified_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: remedy_recommendations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.remedy_recommendations (id, finding_id, challenge_id, judiciary_id, remedy_kind, recommended_text, rationale_text, remedy_timeframe_days, veto_window_days, remedy_due_at, veto_closes_at, clk11_timer_id, clk12_timer_id, record_id, issued_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: removal_proceedings; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.removal_proceedings (id, legislature_id, kind, subject_type, subject_id, source_investigation_id, presided_by_member_id, opened_via, vote_id, status, outcome, closed_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: residency_claims; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.residency_claims (id, user_id, jurisdiction_id, status, declared_at, ping_consent_at, qualifying_days, threshold_days_at_verification, threshold_met_at, verified_at, superseded_at, lapsed_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: residency_confirmations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.residency_confirmations (id, user_id, jurisdiction_id, days_confirmed, confirmed_at, voting_right_active, candidacy_right_active, is_active, deactivated_at, deactivation_reason, created_at, updated_at, claim_id, depth) FROM stdin;
\.


--
-- Data for Name: restoration_events; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.restoration_events (id, jurisdiction_id, condition, evidence, review_case_id, judicially_confirmed, tier, tier_election_id, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: sentencing_orders; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.sentencing_orders (id, case_id, verdict_id, issued_by_seat_id, terms, effective_at, expires_at, status, record_id, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: session_attendance; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.session_attendance (id, session_id, member_id, status, recorded_via_form, recorded_at) FROM stdin;
\.


--
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.sessions (id, user_id, ip_address, user_agent, payload, last_activity) FROM stdin;
\.


--
-- Data for Name: setting_changes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.setting_changes (id, jurisdiction_id, legislature_id, setting_key, old_value, new_value, law_id, applied_at, created_at) FROM stdin;
\.


--
-- Data for Name: social_follows; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.social_follows (id, follower_user_id, target_type, target_id, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: social_memberships; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.social_memberships (id, space_id, user_id, role, block_user_id, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: social_posts; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.social_posts (id, thread_id, author_user_id, author_display, body, is_official, acting_seat, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: social_profiles; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.social_profiles (id, user_id, handle, display_name, bio, visibility, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: social_reactions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.social_reactions (id, post_id, user_id, kind, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: social_spaces; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.social_spaces (id, jurisdiction_id, space_type, title, slug, status, is_private, owner_org_id, created_at, updated_at, deleted_at, owner_user_id) FROM stdin;
\.


--
-- Data for Name: social_subforums; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.social_subforums (id, space_id, governing_object_type, governing_object_id, title, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: social_threads; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.social_threads (id, subforum_id, author_user_id, author_display, title, status, published_record_id, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: spatial_ref_sys; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.spatial_ref_sys (srid, auth_name, auth_srid, srtext, proj4text) FROM stdin;
\.


--
-- Data for Name: standing_attestations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.standing_attestations (id, subject_user_id, device_public_key, issuer_server_id, roles, issued_at, expires_at, signature, source_server_id, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: support_reports; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.support_reports (id, public_id, category, body, ref, reporter_id, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: sync_cursors; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.sync_cursors (id, peer_id, direction, mode, anchor_seq, from_seq, next_from_seq, page_size, pages_applied, records_applied, last_page_hash, status, abort_reason, detail, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: sync_log; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.sync_log (id, peer_id, direction, payload_hash, peer_head_hash, from_seq, to_seq, result, audit_seq, detail, created_at, seq) FROM stdin;
\.


--
-- Data for Name: tabulation_rounds; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.tabulation_rounds (id, tabulation_id, round_no, action, candidacy_id, transfer, tallies, created_at) FROM stdin;
\.


--
-- Data for Name: tabulations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.tabulations (id, race_id, kind, excluded_candidacy_id, engine_version, total_valid, quota, seats, status, started_at, completed_at, record_hash, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: terms; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.terms (id, office_kind, office_type, office_id, holder_user_id, jurisdiction_id, legislature_id, term_class, starts_on, ends_on, source_election_id, source_appointment_id, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: union_processes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.union_processes (id, kind, applicant_jurisdiction_ids, union_jurisdiction_id, compatibility_diff, codified_variables, applicant_referendum_election_id, applicant_supermajority_met, constituent_process_id, status, resulting_jurisdiction_id, initiating_legislature_id, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.users (id, name, display_name, email, email_verified_at, password, status, identity_verified_at, identity_verified_via, terms_accepted_at, languages, timezone, locale, comm_prefs, home_server_id, is_operator, remember_token, created_at, updated_at, deleted_at, invited_by_user_id) FROM stdin;
\.


--
-- Data for Name: vacancies; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.vacancies (id, seat_type, seat_id, legislature_id, jurisdiction_id, declared_by, declared_via_form, status, detected_at, declared_at, countback_tabulation_id, special_election_id, filled_by_user_id, filled_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: verdicts; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.verdicts (id, case_id, decided_by, outcome, panel_vote_for, panel_vote_against, jury_unanimous, summary, double_jeopardy_flag, record_id, decided_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: vote_casts; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.vote_casts (id, vote_id, member_id, lane, value, rankings, is_tiebreak, explanation, cast_via_form, public_record_id, cast_at, board_seat_id) FROM stdin;
\.


--
-- Data for Name: warrants; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.warrants (id, case_id, issued_by_seat_id, kind, stated_reason, max_hold_duration_hours, subject_user_id, status, issued_at, executed_at, expires_at, record_id, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: worldpop_rasters; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.worldpop_rasters (id, iso_code, year, resolution_m, rast, created_at) FROM stdin;
\.


--
-- Data for Name: geocode_settings; Type: TABLE DATA; Schema: tiger; Owner: -
--

COPY tiger.geocode_settings (name, setting, unit, category, short_desc) FROM stdin;
\.


--
-- Data for Name: pagc_gaz; Type: TABLE DATA; Schema: tiger; Owner: -
--

COPY tiger.pagc_gaz (id, seq, word, stdword, token, is_custom) FROM stdin;
\.


--
-- Data for Name: pagc_lex; Type: TABLE DATA; Schema: tiger; Owner: -
--

COPY tiger.pagc_lex (id, seq, word, stdword, token, is_custom) FROM stdin;
\.


--
-- Data for Name: pagc_rules; Type: TABLE DATA; Schema: tiger; Owner: -
--

COPY tiger.pagc_rules (id, rule, is_custom) FROM stdin;
\.


--
-- Data for Name: topology; Type: TABLE DATA; Schema: topology; Owner: -
--

COPY topology.topology (id, name, srid, "precision", hasz) FROM stdin;
\.


--
-- Data for Name: layer; Type: TABLE DATA; Schema: topology; Owner: -
--

COPY topology.layer (topology_id, layer_id, schema_name, table_name, feature_column, feature_type, level, child_id) FROM stdin;
\.


--
-- Name: audit_checkpoints_seq_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.audit_checkpoints_seq_seq', 1, false);


--
-- Name: audit_log_seq_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.audit_log_seq_seq', 1, true);


--
-- Name: case_filings_seq_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.case_filings_seq_seq', 1, false);


--
-- Name: cgc_ip_register_seq_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.cgc_ip_register_seq_seq', 1, false);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.failed_jobs_id_seq', 1, false);


--
-- Name: jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.jobs_id_seq', 1, false);


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 196, true);


--
-- Name: public_records_seq_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.public_records_seq_seq', 1, false);


--
-- Name: sync_log_seq_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.sync_log_seq_seq', 1, false);


--
-- Name: topology_id_seq; Type: SEQUENCE SET; Schema: topology; Owner: -
--

SELECT pg_catalog.setval('topology.topology_id_seq', 1, false);


--
-- Name: achievements achievements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.achievements
    ADD CONSTRAINT achievements_pkey PRIMARY KEY (id);


--
-- Name: actor_devices actor_devices_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.actor_devices
    ADD CONSTRAINT actor_devices_pkey PRIMARY KEY (id);


--
-- Name: admin_offices admin_offices_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_offices
    ADD CONSTRAINT admin_offices_pkey PRIMARY KEY (id);


--
-- Name: advocates advocates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.advocates
    ADD CONSTRAINT advocates_pkey PRIMARY KEY (id);


--
-- Name: appointments appointments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_pkey PRIMARY KEY (id);


--
-- Name: appropriations appropriations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appropriations
    ADD CONSTRAINT appropriations_pkey PRIMARY KEY (id);


--
-- Name: approval_standings approval_standings_candidacy_id_as_of_date_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.approval_standings
    ADD CONSTRAINT approval_standings_candidacy_id_as_of_date_unique UNIQUE (candidacy_id, as_of_date);


--
-- Name: approval_standings approval_standings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.approval_standings
    ADD CONSTRAINT approval_standings_pkey PRIMARY KEY (id);


--
-- Name: approvals approvals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.approvals
    ADD CONSTRAINT approvals_pkey PRIMARY KEY (id);


--
-- Name: attestation_revocations attestation_revocations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attestation_revocations
    ADD CONSTRAINT attestation_revocations_pkey PRIMARY KEY (id);


--
-- Name: audit_chain_reconciliations audit_chain_reconciliations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_chain_reconciliations
    ADD CONSTRAINT audit_chain_reconciliations_pkey PRIMARY KEY (id);


--
-- Name: audit_checkpoints audit_checkpoints_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_checkpoints
    ADD CONSTRAINT audit_checkpoints_pkey PRIMARY KEY (id);


--
-- Name: audit_checkpoints audit_checkpoints_seq_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_checkpoints
    ADD CONSTRAINT audit_checkpoints_seq_unique UNIQUE (seq);


--
-- Name: audit_log audit_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_log
    ADD CONSTRAINT audit_log_pkey PRIMARY KEY (id);


--
-- Name: audit_log audit_log_seq_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_log
    ADD CONSTRAINT audit_log_seq_unique UNIQUE (seq);


--
-- Name: authority_claims authority_claims_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authority_claims
    ADD CONSTRAINT authority_claims_pkey PRIMARY KEY (id);


--
-- Name: ballot_envelopes ballot_envelopes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ballot_envelopes
    ADD CONSTRAINT ballot_envelopes_pkey PRIMARY KEY (id);


--
-- Name: ballots ballots_ballot_hash_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ballots
    ADD CONSTRAINT ballots_ballot_hash_unique UNIQUE (ballot_hash);


--
-- Name: ballots ballots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ballots
    ADD CONSTRAINT ballots_pkey PRIMARY KEY (id);


--
-- Name: bill_versions bill_versions_bill_id_version_no_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bill_versions
    ADD CONSTRAINT bill_versions_bill_id_version_no_unique UNIQUE (bill_id, version_no);


--
-- Name: bill_versions bill_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bill_versions
    ADD CONSTRAINT bill_versions_pkey PRIMARY KEY (id);


--
-- Name: bills bills_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bills
    ADD CONSTRAINT bills_pkey PRIMARY KEY (id);


--
-- Name: board_seats board_seats_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.board_seats
    ADD CONSTRAINT board_seats_pkey PRIMARY KEY (id);


--
-- Name: boards boards_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.boards
    ADD CONSTRAINT boards_pkey PRIMARY KEY (id);


--
-- Name: border_settlements border_settlements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.border_settlements
    ADD CONSTRAINT border_settlements_pkey PRIMARY KEY (id);


--
-- Name: broker_authorizations broker_authorizations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broker_authorizations
    ADD CONSTRAINT broker_authorizations_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: candidacies candidacies_election_id_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.candidacies
    ADD CONSTRAINT candidacies_election_id_user_id_unique UNIQUE (election_id, user_id);


--
-- Name: candidacies candidacies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.candidacies
    ADD CONSTRAINT candidacies_pkey PRIMARY KEY (id);


--
-- Name: case_filings case_filings_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_filings
    ADD CONSTRAINT case_filings_id_key UNIQUE (id);


--
-- Name: case_filings case_filings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_filings
    ADD CONSTRAINT case_filings_pkey PRIMARY KEY (seq);


--
-- Name: case_parties case_parties_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_parties
    ADD CONSTRAINT case_parties_pkey PRIMARY KEY (id);


--
-- Name: cases cases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cases
    ADD CONSTRAINT cases_pkey PRIMARY KEY (id);


--
-- Name: cgc_ip_register cgc_ip_register_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cgc_ip_register
    ADD CONSTRAINT cgc_ip_register_id_key UNIQUE (id);


--
-- Name: cgc_ip_register cgc_ip_register_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cgc_ip_register
    ADD CONSTRAINT cgc_ip_register_pkey PRIMARY KEY (seq);


--
-- Name: chamber_vote_proposals chamber_vote_proposals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chamber_vote_proposals
    ADD CONSTRAINT chamber_vote_proposals_pkey PRIMARY KEY (id);


--
-- Name: chamber_vote_proposals chamber_vote_proposals_vote_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chamber_vote_proposals
    ADD CONSTRAINT chamber_vote_proposals_vote_id_unique UNIQUE (vote_id);


--
-- Name: chamber_vote_tallies chamber_vote_tallies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chamber_vote_tallies
    ADD CONSTRAINT chamber_vote_tallies_pkey PRIMARY KEY (id);


--
-- Name: chamber_vote_tallies chamber_vote_tallies_vote_id_lane_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chamber_vote_tallies
    ADD CONSTRAINT chamber_vote_tallies_vote_id_lane_unique UNIQUE (vote_id, lane);


--
-- Name: chamber_votes chamber_votes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chamber_votes
    ADD CONSTRAINT chamber_votes_pkey PRIMARY KEY (id);


--
-- Name: clock_timers clock_timers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clock_timers
    ADD CONSTRAINT clock_timers_pkey PRIMARY KEY (id);


--
-- Name: clocks clocks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clocks
    ADD CONSTRAINT clocks_pkey PRIMARY KEY (id);


--
-- Name: cluster_adoption_requests cluster_adoption_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cluster_adoption_requests
    ADD CONSTRAINT cluster_adoption_requests_pkey PRIMARY KEY (id);


--
-- Name: cluster_join_keys cluster_join_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cluster_join_keys
    ADD CONSTRAINT cluster_join_keys_pkey PRIMARY KEY (id);


--
-- Name: cluster_members cluster_members_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cluster_members
    ADD CONSTRAINT cluster_members_pkey PRIMARY KEY (id);


--
-- Name: cluster_memberships cluster_memberships_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cluster_memberships
    ADD CONSTRAINT cluster_memberships_pkey PRIMARY KEY (id);


--
-- Name: clusters clusters_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clusters
    ADD CONSTRAINT clusters_pkey PRIMARY KEY (id);


--
-- Name: committee_meetings committee_meetings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committee_meetings
    ADD CONSTRAINT committee_meetings_pkey PRIMARY KEY (id);


--
-- Name: committee_preferences committee_preferences_legislature_id_member_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committee_preferences
    ADD CONSTRAINT committee_preferences_legislature_id_member_id_unique UNIQUE (legislature_id, member_id);


--
-- Name: committee_preferences committee_preferences_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committee_preferences
    ADD CONSTRAINT committee_preferences_pkey PRIMARY KEY (id);


--
-- Name: committee_reports committee_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committee_reports
    ADD CONSTRAINT committee_reports_pkey PRIMARY KEY (id);


--
-- Name: committee_seats committee_seats_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committee_seats
    ADD CONSTRAINT committee_seats_pkey PRIMARY KEY (id);


--
-- Name: committees committees_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committees
    ADD CONSTRAINT committees_pkey PRIMARY KEY (id);


--
-- Name: constituent_consents constituent_consents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constituent_consents
    ADD CONSTRAINT constituent_consents_pkey PRIMARY KEY (id);


--
-- Name: constituent_consents constituent_consents_process_id_jurisdiction_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constituent_consents
    ADD CONSTRAINT constituent_consents_process_id_jurisdiction_id_unique UNIQUE (process_id, jurisdiction_id);


--
-- Name: constitutional_challenges constitutional_challenges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_challenges
    ADD CONSTRAINT constitutional_challenges_pkey PRIMARY KEY (id);


--
-- Name: constitutional_findings constitutional_findings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_findings
    ADD CONSTRAINT constitutional_findings_pkey PRIMARY KEY (id);


--
-- Name: constitutional_settings constitutional_settings_jurisdiction_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_settings
    ADD CONSTRAINT constitutional_settings_jurisdiction_id_unique UNIQUE (jurisdiction_id);


--
-- Name: constitutional_settings constitutional_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_settings
    ADD CONSTRAINT constitutional_settings_pkey PRIMARY KEY (id);


--
-- Name: cosmic_addresses cosmic_addresses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cosmic_addresses
    ADD CONSTRAINT cosmic_addresses_pkey PRIMARY KEY (id);


--
-- Name: cosmic_addresses cosmic_addresses_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cosmic_addresses
    ADD CONSTRAINT cosmic_addresses_slug_unique UNIQUE (slug);


--
-- Name: cultural_institutions cultural_institutions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cultural_institutions
    ADD CONSTRAINT cultural_institutions_pkey PRIMARY KEY (id);


--
-- Name: data_review_decisions data_review_decisions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_review_decisions
    ADD CONSTRAINT data_review_decisions_pkey PRIMARY KEY (id);


--
-- Name: data_review_decisions data_review_decisions_unique_active; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_review_decisions
    ADD CONSTRAINT data_review_decisions_unique_active UNIQUE (category, jurisdiction_id, deleted_at);


--
-- Name: department_reports department_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.department_reports
    ADD CONSTRAINT department_reports_pkey PRIMARY KEY (id);


--
-- Name: department_rules department_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.department_rules
    ADD CONSTRAINT department_rules_pkey PRIMARY KEY (id);


--
-- Name: departments departments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_pkey PRIMARY KEY (id);


--
-- Name: directory_entries directory_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.directory_entries
    ADD CONSTRAINT directory_entries_pkey PRIMARY KEY (id);


--
-- Name: disintermediation_processes disintermediation_processes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.disintermediation_processes
    ADD CONSTRAINT disintermediation_processes_pkey PRIMARY KEY (id);


--
-- Name: district_subdivisions district_subdivisions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.district_subdivisions
    ADD CONSTRAINT district_subdivisions_pkey PRIMARY KEY (id);


--
-- Name: election_audits election_audits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_audits
    ADD CONSTRAINT election_audits_pkey PRIMARY KEY (id);


--
-- Name: election_ballot_key_rewraps election_ballot_key_rewraps_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_ballot_key_rewraps
    ADD CONSTRAINT election_ballot_key_rewraps_pkey PRIMARY KEY (id);


--
-- Name: election_board_members election_board_members_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_board_members
    ADD CONSTRAINT election_board_members_pkey PRIMARY KEY (id);


--
-- Name: election_boards election_boards_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_boards
    ADD CONSTRAINT election_boards_pkey PRIMARY KEY (id);


--
-- Name: election_certifications election_certifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_certifications
    ADD CONSTRAINT election_certifications_pkey PRIMARY KEY (id);


--
-- Name: election_races election_races_election_id_district_id_seat_kind_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_races
    ADD CONSTRAINT election_races_election_id_district_id_seat_kind_unique UNIQUE (election_id, district_id, seat_kind);


--
-- Name: election_races election_races_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_races
    ADD CONSTRAINT election_races_pkey PRIMARY KEY (id);


--
-- Name: elections elections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.elections
    ADD CONSTRAINT elections_pkey PRIMARY KEY (id);


--
-- Name: emergency_power_renewals emergency_power_renewals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emergency_power_renewals
    ADD CONSTRAINT emergency_power_renewals_pkey PRIMARY KEY (id);


--
-- Name: emergency_power_reviews emergency_power_reviews_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emergency_power_reviews
    ADD CONSTRAINT emergency_power_reviews_pkey PRIMARY KEY (id);


--
-- Name: emergency_powers emergency_powers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emergency_powers
    ADD CONSTRAINT emergency_powers_pkey PRIMARY KEY (id);


--
-- Name: endorsement_requests endorsement_requests_candidacy_id_organization_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.endorsement_requests
    ADD CONSTRAINT endorsement_requests_candidacy_id_organization_id_unique UNIQUE (candidacy_id, organization_id);


--
-- Name: endorsement_requests endorsement_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.endorsement_requests
    ADD CONSTRAINT endorsement_requests_pkey PRIMARY KEY (id);


--
-- Name: endorsements endorsements_election_id_candidate_id_endorser_type_endorser_id; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.endorsements
    ADD CONSTRAINT endorsements_election_id_candidate_id_endorser_type_endorser_id UNIQUE (election_id, candidate_id, endorser_type, endorser_id);


--
-- Name: endorsements endorsements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.endorsements
    ADD CONSTRAINT endorsements_pkey PRIMARY KEY (id);


--
-- Name: executive_investigations executive_investigations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executive_investigations
    ADD CONSTRAINT executive_investigations_pkey PRIMARY KEY (id);


--
-- Name: executive_members executive_members_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executive_members
    ADD CONSTRAINT executive_members_pkey PRIMARY KEY (id);


--
-- Name: executive_orders executive_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executive_orders
    ADD CONSTRAINT executive_orders_pkey PRIMARY KEY (id);


--
-- Name: executives executives_jurisdiction_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executives
    ADD CONSTRAINT executives_jurisdiction_unique UNIQUE (jurisdiction_id, deleted_at);


--
-- Name: executives executives_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executives
    ADD CONSTRAINT executives_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: federation_peers federation_peers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.federation_peers
    ADD CONSTRAINT federation_peers_pkey PRIMARY KEY (id);


--
-- Name: federation_transport_health federation_transport_health_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.federation_transport_health
    ADD CONSTRAINT federation_transport_health_pkey PRIMARY KEY (id);


--
-- Name: federation_transports federation_transports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.federation_transports
    ADD CONSTRAINT federation_transports_pkey PRIMARY KEY (id);


--
-- Name: finding_offending_laws finding_offending_laws_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.finding_offending_laws
    ADD CONSTRAINT finding_offending_laws_pkey PRIMARY KEY (id);


--
-- Name: forwarded_writes forwarded_writes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.forwarded_writes
    ADD CONSTRAINT forwarded_writes_pkey PRIMARY KEY (id);


--
-- Name: foundation_sync_cursors foundation_sync_cursors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foundation_sync_cursors
    ADD CONSTRAINT foundation_sync_cursors_pkey PRIMARY KEY (id);


--
-- Name: geoboundary_metadata geoboundary_metadata_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geoboundary_metadata
    ADD CONSTRAINT geoboundary_metadata_pkey PRIMARY KEY (iso_code, adm_level);


--
-- Name: geodata_dataset_manifests geodata_dataset_manifests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geodata_dataset_manifests
    ADD CONSTRAINT geodata_dataset_manifests_pkey PRIMARY KEY (id);


--
-- Name: governor_removal_requests governor_removal_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.governor_removal_requests
    ADD CONSTRAINT governor_removal_requests_pkey PRIMARY KEY (id);


--
-- Name: grant_applications grant_applications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grant_applications
    ADD CONSTRAINT grant_applications_pkey PRIMARY KEY (id);


--
-- Name: grant_disbursements grant_disbursements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grant_disbursements
    ADD CONSTRAINT grant_disbursements_pkey PRIMARY KEY (id);


--
-- Name: instance_capabilities instance_capabilities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.instance_capabilities
    ADD CONSTRAINT instance_capabilities_pkey PRIMARY KEY (id);


--
-- Name: instance_settings instance_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.instance_settings
    ADD CONSTRAINT instance_settings_pkey PRIMARY KEY (id);


--
-- Name: invites invites_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invites
    ADD CONSTRAINT invites_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: journey_progress journey_progress_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journey_progress
    ADD CONSTRAINT journey_progress_pkey PRIMARY KEY (id);


--
-- Name: journey_progress journey_progress_user_id_journey_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journey_progress
    ADD CONSTRAINT journey_progress_user_id_journey_id_unique UNIQUE (user_id, journey_id);


--
-- Name: judicial_nominations judicial_nominations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_nominations
    ADD CONSTRAINT judicial_nominations_pkey PRIMARY KEY (id);


--
-- Name: judicial_seats judicial_seats_judiciary_seat_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_seats
    ADD CONSTRAINT judicial_seats_judiciary_seat_unique UNIQUE (judiciary_id, seat_number, deleted_at);


--
-- Name: judicial_seats judicial_seats_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_seats
    ADD CONSTRAINT judicial_seats_pkey PRIMARY KEY (id);


--
-- Name: judiciaries judiciaries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judiciaries
    ADD CONSTRAINT judiciaries_pkey PRIMARY KEY (id);


--
-- Name: juries juries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.juries
    ADD CONSTRAINT juries_pkey PRIMARY KEY (id);


--
-- Name: jurisdiction_activations jurisdiction_activations_jurisdiction_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_activations
    ADD CONSTRAINT jurisdiction_activations_jurisdiction_id_unique UNIQUE (jurisdiction_id);


--
-- Name: jurisdiction_activations jurisdiction_activations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_activations
    ADD CONSTRAINT jurisdiction_activations_pkey PRIMARY KEY (id);


--
-- Name: jurisdiction_maps jurisdiction_maps_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_maps
    ADD CONSTRAINT jurisdiction_maps_pkey PRIMARY KEY (id);


--
-- Name: jurisdictions jurisdictions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdictions
    ADD CONSTRAINT jurisdictions_pkey PRIMARY KEY (id);


--
-- Name: jurisdictions jurisdictions_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdictions
    ADD CONSTRAINT jurisdictions_slug_unique UNIQUE (slug);


--
-- Name: jury_members jury_members_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jury_members
    ADD CONSTRAINT jury_members_pkey PRIMARY KEY (id);


--
-- Name: law_merge_resolutions law_merge_resolutions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.law_merge_resolutions
    ADD CONSTRAINT law_merge_resolutions_pkey PRIMARY KEY (id);


--
-- Name: law_versions law_versions_law_id_version_no_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.law_versions
    ADD CONSTRAINT law_versions_law_id_version_no_unique UNIQUE (law_id, version_no);


--
-- Name: law_versions law_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.law_versions
    ADD CONSTRAINT law_versions_pkey PRIMARY KEY (id);


--
-- Name: laws laws_legislature_id_act_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.laws
    ADD CONSTRAINT laws_legislature_id_act_number_unique UNIQUE (legislature_id, act_number);


--
-- Name: laws laws_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.laws
    ADD CONSTRAINT laws_pkey PRIMARY KEY (id);


--
-- Name: legal_compliance_removals legal_compliance_removals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_compliance_removals
    ADD CONSTRAINT legal_compliance_removals_pkey PRIMARY KEY (id);


--
-- Name: legislature_district_jurisdictions legislature_district_jurisdictions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_district_jurisdictions
    ADD CONSTRAINT legislature_district_jurisdictions_pkey PRIMARY KEY (id);


--
-- Name: legislature_district_maps legislature_district_maps_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_district_maps
    ADD CONSTRAINT legislature_district_maps_pkey PRIMARY KEY (id);


--
-- Name: legislature_districts legislature_districts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_districts
    ADD CONSTRAINT legislature_districts_pkey PRIMARY KEY (id);


--
-- Name: legislature_members legislature_members_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_members
    ADD CONSTRAINT legislature_members_pkey PRIMARY KEY (id);


--
-- Name: legislature_sessions legislature_sessions_legislature_id_session_no_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_sessions
    ADD CONSTRAINT legislature_sessions_legislature_id_session_no_unique UNIQUE (legislature_id, session_no);


--
-- Name: legislature_sessions legislature_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_sessions
    ADD CONSTRAINT legislature_sessions_pkey PRIMARY KEY (id);


--
-- Name: legislatures legislatures_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislatures
    ADD CONSTRAINT legislatures_pkey PRIMARY KEY (id);


--
-- Name: local_autonomy_processes local_autonomy_processes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.local_autonomy_processes
    ADD CONSTRAINT local_autonomy_processes_pkey PRIMARY KEY (id);


--
-- Name: location_pings location_pings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.location_pings
    ADD CONSTRAINT location_pings_pkey PRIMARY KEY (id);


--
-- Name: matrix_carveout_log matrix_carveout_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.matrix_carveout_log
    ADD CONSTRAINT matrix_carveout_log_pkey PRIMARY KEY (id);


--
-- Name: matrix_event_snapshots matrix_event_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.matrix_event_snapshots
    ADD CONSTRAINT matrix_event_snapshots_pkey PRIMARY KEY (id);


--
-- Name: matrix_identities matrix_identities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.matrix_identities
    ADD CONSTRAINT matrix_identities_pkey PRIMARY KEY (id);


--
-- Name: matrix_rooms matrix_rooms_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.matrix_rooms
    ADD CONSTRAINT matrix_rooms_pkey PRIMARY KEY (id);


--
-- Name: matrix_server_acls matrix_server_acls_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.matrix_server_acls
    ADD CONSTRAINT matrix_server_acls_pkey PRIMARY KEY (id);


--
-- Name: mesh_operator_identities mesh_operator_identities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mesh_operator_identities
    ADD CONSTRAINT mesh_operator_identities_pkey PRIMARY KEY (id);


--
-- Name: mesh_operator_keys mesh_operator_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mesh_operator_keys
    ADD CONSTRAINT mesh_operator_keys_pkey PRIMARY KEY (id);


--
-- Name: mesh_operator_local_links mesh_operator_local_links_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mesh_operator_local_links
    ADD CONSTRAINT mesh_operator_local_links_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: misconduct_investigations misconduct_investigations_admin_office_id_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.misconduct_investigations
    ADD CONSTRAINT misconduct_investigations_admin_office_id_code_unique UNIQUE (admin_office_id, code);


--
-- Name: misconduct_investigations misconduct_investigations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.misconduct_investigations
    ADD CONSTRAINT misconduct_investigations_pkey PRIMARY KEY (id);


--
-- Name: motions motions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.motions
    ADD CONSTRAINT motions_pkey PRIMARY KEY (id);


--
-- Name: multi_jurisdiction_votes multi_jurisdiction_votes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.multi_jurisdiction_votes
    ADD CONSTRAINT multi_jurisdiction_votes_pkey PRIMARY KEY (id);


--
-- Name: oidc_authorization_codes oidc_authorization_codes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oidc_authorization_codes
    ADD CONSTRAINT oidc_authorization_codes_pkey PRIMARY KEY (id);


--
-- Name: oidc_signing_keys oidc_signing_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oidc_signing_keys
    ADD CONSTRAINT oidc_signing_keys_pkey PRIMARY KEY (id);


--
-- Name: operational_partition_exports operational_partition_exports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.operational_partition_exports
    ADD CONSTRAINT operational_partition_exports_pkey PRIMARY KEY (id);


--
-- Name: operator_accounts operator_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.operator_accounts
    ADD CONSTRAINT operator_accounts_pkey PRIMARY KEY (id);


--
-- Name: operator_devices operator_devices_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.operator_devices
    ADD CONSTRAINT operator_devices_pkey PRIMARY KEY (id);


--
-- Name: opinion_law_links opinion_law_links_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.opinion_law_links
    ADD CONSTRAINT opinion_law_links_pkey PRIMARY KEY (id);


--
-- Name: opinions opinions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.opinions
    ADD CONSTRAINT opinions_pkey PRIMARY KEY (id);


--
-- Name: org_contracts org_contracts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_contracts
    ADD CONSTRAINT org_contracts_pkey PRIMARY KEY (id);


--
-- Name: org_conversions org_conversions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_conversions
    ADD CONSTRAINT org_conversions_pkey PRIMARY KEY (id);


--
-- Name: org_document_package_versions org_document_package_versions_package_id_version_no_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_document_package_versions
    ADD CONSTRAINT org_document_package_versions_package_id_version_no_unique UNIQUE (package_id, version_no);


--
-- Name: org_document_package_versions org_document_package_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_document_package_versions
    ADD CONSTRAINT org_document_package_versions_pkey PRIMARY KEY (id);


--
-- Name: org_document_packages org_document_packages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_document_packages
    ADD CONSTRAINT org_document_packages_pkey PRIMARY KEY (id);


--
-- Name: org_memberships org_memberships_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_memberships
    ADD CONSTRAINT org_memberships_pkey PRIMARY KEY (id);


--
-- Name: org_ownership_stakes org_ownership_stakes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_ownership_stakes
    ADD CONSTRAINT org_ownership_stakes_pkey PRIMARY KEY (id);


--
-- Name: org_transfers org_transfers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_transfers
    ADD CONSTRAINT org_transfers_pkey PRIMARY KEY (id);


--
-- Name: org_workers org_workers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_workers
    ADD CONSTRAINT org_workers_pkey PRIMARY KEY (id);


--
-- Name: organizations organizations_jurisdiction_id_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_jurisdiction_id_slug_unique UNIQUE (jurisdiction_id, slug);


--
-- Name: organizations organizations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_pkey PRIMARY KEY (id);


--
-- Name: panel_judges panel_judges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.panel_judges
    ADD CONSTRAINT panel_judges_pkey PRIMARY KEY (id);


--
-- Name: panels panels_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.panels
    ADD CONSTRAINT panels_pkey PRIMARY KEY (id);


--
-- Name: partition_exports partition_exports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.partition_exports
    ADD CONSTRAINT partition_exports_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: peer_upgrade_consents peer_upgrade_consents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.peer_upgrade_consents
    ADD CONSTRAINT peer_upgrade_consents_pkey PRIMARY KEY (id);


--
-- Name: peer_upgrade_proposals peer_upgrade_proposals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.peer_upgrade_proposals
    ADD CONSTRAINT peer_upgrade_proposals_pkey PRIMARY KEY (id);


--
-- Name: petition_signatures petition_signatures_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.petition_signatures
    ADD CONSTRAINT petition_signatures_pkey PRIMARY KEY (id);


--
-- Name: petitions petitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.petitions
    ADD CONSTRAINT petitions_pkey PRIMARY KEY (id);


--
-- Name: policy_proposals policy_proposals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.policy_proposals
    ADD CONSTRAINT policy_proposals_pkey PRIMARY KEY (id);


--
-- Name: public_records public_records_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.public_records
    ADD CONSTRAINT public_records_id_unique UNIQUE (id);


--
-- Name: public_records public_records_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.public_records
    ADD CONSTRAINT public_records_pkey PRIMARY KEY (seq);


--
-- Name: race_results race_results_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.race_results
    ADD CONSTRAINT race_results_pkey PRIMARY KEY (id);


--
-- Name: race_results race_results_tabulation_id_candidacy_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.race_results
    ADD CONSTRAINT race_results_tabulation_id_candidacy_id_unique UNIQUE (tabulation_id, candidacy_id);


--
-- Name: read_write_requests read_write_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.read_write_requests
    ADD CONSTRAINT read_write_requests_pkey PRIMARY KEY (id);


--
-- Name: referendum_questions referendum_questions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.referendum_questions
    ADD CONSTRAINT referendum_questions_pkey PRIMARY KEY (id);


--
-- Name: remedy_recommendations remedy_recommendations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.remedy_recommendations
    ADD CONSTRAINT remedy_recommendations_pkey PRIMARY KEY (id);


--
-- Name: removal_proceedings removal_proceedings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.removal_proceedings
    ADD CONSTRAINT removal_proceedings_pkey PRIMARY KEY (id);


--
-- Name: residency_claims residency_claims_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.residency_claims
    ADD CONSTRAINT residency_claims_pkey PRIMARY KEY (id);


--
-- Name: residency_confirmations residency_confirmations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.residency_confirmations
    ADD CONSTRAINT residency_confirmations_pkey PRIMARY KEY (id);


--
-- Name: restoration_events restoration_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restoration_events
    ADD CONSTRAINT restoration_events_pkey PRIMARY KEY (id);


--
-- Name: sentencing_orders sentencing_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sentencing_orders
    ADD CONSTRAINT sentencing_orders_pkey PRIMARY KEY (id);


--
-- Name: session_attendance session_attendance_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_attendance
    ADD CONSTRAINT session_attendance_pkey PRIMARY KEY (id);


--
-- Name: session_attendance session_attendance_session_id_member_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_attendance
    ADD CONSTRAINT session_attendance_session_id_member_id_unique UNIQUE (session_id, member_id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: setting_changes setting_changes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.setting_changes
    ADD CONSTRAINT setting_changes_pkey PRIMARY KEY (id);


--
-- Name: social_follows social_follows_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.social_follows
    ADD CONSTRAINT social_follows_pkey PRIMARY KEY (id);


--
-- Name: social_memberships social_memberships_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.social_memberships
    ADD CONSTRAINT social_memberships_pkey PRIMARY KEY (id);


--
-- Name: social_posts social_posts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.social_posts
    ADD CONSTRAINT social_posts_pkey PRIMARY KEY (id);


--
-- Name: social_profiles social_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.social_profiles
    ADD CONSTRAINT social_profiles_pkey PRIMARY KEY (id);


--
-- Name: social_reactions social_reactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.social_reactions
    ADD CONSTRAINT social_reactions_pkey PRIMARY KEY (id);


--
-- Name: social_spaces social_spaces_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.social_spaces
    ADD CONSTRAINT social_spaces_pkey PRIMARY KEY (id);


--
-- Name: social_subforums social_subforums_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.social_subforums
    ADD CONSTRAINT social_subforums_pkey PRIMARY KEY (id);


--
-- Name: social_threads social_threads_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.social_threads
    ADD CONSTRAINT social_threads_pkey PRIMARY KEY (id);


--
-- Name: standing_attestations standing_attestations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.standing_attestations
    ADD CONSTRAINT standing_attestations_pkey PRIMARY KEY (id);


--
-- Name: support_reports support_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.support_reports
    ADD CONSTRAINT support_reports_pkey PRIMARY KEY (id);


--
-- Name: support_reports support_reports_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.support_reports
    ADD CONSTRAINT support_reports_public_id_unique UNIQUE (public_id);


--
-- Name: sync_cursors sync_cursors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sync_cursors
    ADD CONSTRAINT sync_cursors_pkey PRIMARY KEY (id);


--
-- Name: sync_log sync_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sync_log
    ADD CONSTRAINT sync_log_pkey PRIMARY KEY (id);


--
-- Name: sync_log sync_log_seq_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sync_log
    ADD CONSTRAINT sync_log_seq_unique UNIQUE (seq);


--
-- Name: tabulation_rounds tabulation_rounds_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tabulation_rounds
    ADD CONSTRAINT tabulation_rounds_pkey PRIMARY KEY (id);


--
-- Name: tabulation_rounds tabulation_rounds_tabulation_id_round_no_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tabulation_rounds
    ADD CONSTRAINT tabulation_rounds_tabulation_id_round_no_unique UNIQUE (tabulation_id, round_no);


--
-- Name: tabulations tabulations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tabulations
    ADD CONSTRAINT tabulations_pkey PRIMARY KEY (id);


--
-- Name: terms terms_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.terms
    ADD CONSTRAINT terms_pkey PRIMARY KEY (id);


--
-- Name: union_processes union_processes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.union_processes
    ADD CONSTRAINT union_processes_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: vacancies vacancies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vacancies
    ADD CONSTRAINT vacancies_pkey PRIMARY KEY (id);


--
-- Name: verdicts verdicts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.verdicts
    ADD CONSTRAINT verdicts_pkey PRIMARY KEY (id);


--
-- Name: vote_casts vote_casts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vote_casts
    ADD CONSTRAINT vote_casts_pkey PRIMARY KEY (id);


--
-- Name: warrants warrants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warrants
    ADD CONSTRAINT warrants_pkey PRIMARY KEY (id);


--
-- Name: worldpop_rasters worldpop_rasters_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.worldpop_rasters
    ADD CONSTRAINT worldpop_rasters_pkey PRIMARY KEY (id);


--
-- Name: achievements_journey_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX achievements_journey_id_index ON public.achievements USING btree (journey_id);


--
-- Name: achievements_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX achievements_user_id_index ON public.achievements USING btree (user_id);


--
-- Name: achievements_user_journey_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX achievements_user_journey_unique ON public.achievements USING btree (user_id, journey_id) WHERE (deleted_at IS NULL);


--
-- Name: actor_devices_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX actor_devices_user_id_index ON public.actor_devices USING btree (user_id);


--
-- Name: actor_devices_user_key_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX actor_devices_user_key_unique ON public.actor_devices USING btree (user_id, device_public_key) WHERE (deleted_at IS NULL);


--
-- Name: admin_offices_one_live; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX admin_offices_one_live ON public.admin_offices USING btree (legislature_id) WHERE (((status)::text <> 'dissolved'::text) AND (deleted_at IS NULL));


--
-- Name: advocates_judiciary_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX advocates_judiciary_id_status_index ON public.advocates USING btree (judiciary_id, status);


--
-- Name: advocates_user_judiciary_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX advocates_user_judiciary_unique ON public.advocates USING btree (user_id, judiciary_id) WHERE (deleted_at IS NULL);


--
-- Name: appointments_appointable_type_appointable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX appointments_appointable_type_appointable_id_index ON public.appointments USING btree (appointable_type, appointable_id);


--
-- Name: appropriations_executive_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX appropriations_executive_id_status_index ON public.appropriations USING btree (executive_id, status);


--
-- Name: approval_standings_race_id_as_of_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX approval_standings_race_id_as_of_date_index ON public.approval_standings USING btree (race_id, as_of_date);


--
-- Name: approvals_active_by_candidacy; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX approvals_active_by_candidacy ON public.approvals USING btree (candidacy_id) WHERE (revoked_at IS NULL);


--
-- Name: approvals_one_active; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX approvals_one_active ON public.approvals USING btree (candidacy_id, user_id) WHERE (revoked_at IS NULL);


--
-- Name: approvals_user_id_election_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX approvals_user_id_election_id_index ON public.approvals USING btree (user_id, election_id);


--
-- Name: attestation_revocations_attestation_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX attestation_revocations_attestation_id_index ON public.attestation_revocations USING btree (attestation_id);


--
-- Name: attestation_revocations_one_per_attestation_issuer; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX attestation_revocations_one_per_attestation_issuer ON public.attestation_revocations USING btree (attestation_id, issuer_server_id) WHERE (deleted_at IS NULL);


--
-- Name: audit_chain_reconciliations_break_seq_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_chain_reconciliations_break_seq_index ON public.audit_chain_reconciliations USING btree (break_seq);


--
-- Name: audit_chain_reconciliations_break_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX audit_chain_reconciliations_break_unique ON public.audit_chain_reconciliations USING btree (break_seq, observed_prev_hash) WHERE (deleted_at IS NULL);


--
-- Name: audit_checkpoints_audit_seq_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_checkpoints_audit_seq_index ON public.audit_checkpoints USING btree (audit_seq);


--
-- Name: audit_log_actor_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_log_actor_user_id_index ON public.audit_log USING btree (actor_user_id);


--
-- Name: audit_log_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_log_jurisdiction_id_index ON public.audit_log USING btree (jurisdiction_id);


--
-- Name: audit_log_module_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_log_module_index ON public.audit_log USING btree (module);


--
-- Name: audit_log_ref_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_log_ref_index ON public.audit_log USING btree (ref);


--
-- Name: authority_claims_claimed_by_peer_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX authority_claims_claimed_by_peer_id_index ON public.authority_claims USING btree (claimed_by_peer_id);


--
-- Name: authority_claims_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX authority_claims_jurisdiction_id_index ON public.authority_claims USING btree (jurisdiction_id);


--
-- Name: authority_claims_one_authority_per_jurisdiction; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX authority_claims_one_authority_per_jurisdiction ON public.authority_claims USING btree (jurisdiction_id) WHERE ((deleted_at IS NULL) AND ((resolution)::text = ANY ((ARRAY['uncontested'::character varying, 'recognized'::character varying])::text[])));


--
-- Name: ballot_envelopes_ranked_one_per_voter; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ballot_envelopes_ranked_one_per_voter ON public.ballot_envelopes USING btree (race_id, user_id) WHERE ((kind)::text = 'ranked'::text);


--
-- Name: ballot_envelopes_referendum_one_per_voter; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ballot_envelopes_referendum_one_per_voter ON public.ballot_envelopes USING btree (referendum_question_id, user_id) WHERE ((kind)::text = 'referendum'::text);


--
-- Name: ballot_envelopes_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ballot_envelopes_user_id_index ON public.ballot_envelopes USING btree (user_id);


--
-- Name: ballots_race_id_counted_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ballots_race_id_counted_index ON public.ballots USING btree (race_id, counted);


--
-- Name: ballots_referendum_question_counted_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ballots_referendum_question_counted_idx ON public.ballots USING btree (referendum_question_id, counted);


--
-- Name: bills_legislature_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bills_legislature_id_status_index ON public.bills USING btree (legislature_id, status);


--
-- Name: bills_targets_setting_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bills_targets_setting_key_index ON public.bills USING btree (targets_setting_key);


--
-- Name: board_seats_board_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX board_seats_board_id_status_index ON public.board_seats USING btree (board_id, status);


--
-- Name: board_seats_holder_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX board_seats_holder_user_id_index ON public.board_seats USING btree (holder_user_id);


--
-- Name: board_seats_one_chair; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX board_seats_one_chair ON public.board_seats USING btree (board_id) WHERE (is_chair AND ((status)::text = 'seated'::text) AND (deleted_at IS NULL));


--
-- Name: board_seats_one_seat_no; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX board_seats_one_seat_no ON public.board_seats USING btree (board_id, seat_no) WHERE (deleted_at IS NULL);


--
-- Name: boards_boardable_type_boardable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX boards_boardable_type_boardable_id_index ON public.boards USING btree (boardable_type, boardable_id);


--
-- Name: boards_one_per_body; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX boards_one_per_body ON public.boards USING btree (boardable_type, boardable_id) WHERE (deleted_at IS NULL);


--
-- Name: border_settlements_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX border_settlements_status_index ON public.border_settlements USING btree (status);


--
-- Name: broker_authorizations_broker_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX broker_authorizations_broker_server_id_index ON public.broker_authorizations USING btree (broker_server_id);


--
-- Name: broker_authorizations_domain_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX broker_authorizations_domain_index ON public.broker_authorizations USING btree (domain);


--
-- Name: broker_authorizations_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX broker_authorizations_unique ON public.broker_authorizations USING btree (domain, broker_server_id, authority_server_id) WHERE (deleted_at IS NULL);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: candidacies_race_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX candidacies_race_id_status_index ON public.candidacies USING btree (race_id, status);


--
-- Name: candidacies_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX candidacies_user_id_index ON public.candidacies USING btree (user_id);


--
-- Name: case_filings_by_advocate; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX case_filings_by_advocate ON public.case_filings USING btree (advocate_id);


--
-- Name: case_filings_by_case; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX case_filings_by_case ON public.case_filings USING btree (case_id, seq);


--
-- Name: case_filings_by_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX case_filings_by_user ON public.case_filings USING btree (filed_by_user_id);


--
-- Name: case_parties_case_id_party_role_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX case_parties_case_id_party_role_index ON public.case_parties USING btree (case_id, party_role);


--
-- Name: case_parties_party_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX case_parties_party_user_id_index ON public.case_parties USING btree (party_user_id);


--
-- Name: cases_judiciary_docket_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX cases_judiciary_docket_unique ON public.cases USING btree (judiciary_id, docket_no) WHERE (deleted_at IS NULL);


--
-- Name: cases_judiciary_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cases_judiciary_id_status_index ON public.cases USING btree (judiciary_id, status);


--
-- Name: cases_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cases_jurisdiction_id_index ON public.cases USING btree (jurisdiction_id);


--
-- Name: cases_kind_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cases_kind_status_index ON public.cases USING btree (kind, status);


--
-- Name: cgc_ip_register_by_org; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cgc_ip_register_by_org ON public.cgc_ip_register USING btree (organization_id);


--
-- Name: chamber_vote_proposals_legislature_id_proposal_kind_status_inde; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX chamber_vote_proposals_legislature_id_proposal_kind_status_inde ON public.chamber_vote_proposals USING btree (legislature_id, proposal_kind, status);


--
-- Name: chamber_votes_body_type_body_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX chamber_votes_body_type_body_id_index ON public.chamber_votes USING btree (body_type, body_id);


--
-- Name: chamber_votes_legislature_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX chamber_votes_legislature_id_status_index ON public.chamber_votes USING btree (legislature_id, status);


--
-- Name: chamber_votes_votable_type_votable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX chamber_votes_votable_type_votable_id_index ON public.chamber_votes USING btree (votable_type, votable_id);


--
-- Name: clock_timers_clock_id_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX clock_timers_clock_id_jurisdiction_id_index ON public.clock_timers USING btree (clock_id, jurisdiction_id);


--
-- Name: clock_timers_state_fires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX clock_timers_state_fires_at_index ON public.clock_timers USING btree (state, fires_at);


--
-- Name: clock_timers_subject_type_subject_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX clock_timers_subject_type_subject_id_index ON public.clock_timers USING btree (subject_type, subject_id);


--
-- Name: cluster_adoption_requests_applicant_nonce_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX cluster_adoption_requests_applicant_nonce_unique ON public.cluster_adoption_requests USING btree (applicant_server_id, nonce) WHERE (deleted_at IS NULL);


--
-- Name: cluster_adoption_requests_applicant_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cluster_adoption_requests_applicant_server_id_index ON public.cluster_adoption_requests USING btree (applicant_server_id);


--
-- Name: cluster_adoption_requests_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cluster_adoption_requests_status_index ON public.cluster_adoption_requests USING btree (status);


--
-- Name: cluster_join_keys_handle_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX cluster_join_keys_handle_unique ON public.cluster_join_keys USING btree (handle) WHERE (deleted_at IS NULL);


--
-- Name: cluster_members_cluster_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cluster_members_cluster_id_index ON public.cluster_members USING btree (cluster_id);


--
-- Name: cluster_members_one_per_cluster_server; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX cluster_members_one_per_cluster_server ON public.cluster_members USING btree (cluster_id, server_id) WHERE (deleted_at IS NULL);


--
-- Name: cluster_memberships_one_active_mirror; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX cluster_memberships_one_active_mirror ON public.cluster_memberships USING btree (role) WHERE ((deleted_at IS NULL) AND ((role)::text = 'mirror'::text) AND ((state)::text <> ALL ((ARRAY['departed'::character varying, 'rejected'::character varying])::text[])));


--
-- Name: cluster_memberships_one_per_peer_role; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX cluster_memberships_one_per_peer_role ON public.cluster_memberships USING btree (peer_id, role) WHERE (deleted_at IS NULL);


--
-- Name: cluster_memberships_peer_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cluster_memberships_peer_id_index ON public.cluster_memberships USING btree (peer_id);


--
-- Name: cluster_memberships_state_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cluster_memberships_state_index ON public.cluster_memberships USING btree (state);


--
-- Name: clusters_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX clusters_jurisdiction_id_index ON public.clusters USING btree (jurisdiction_id);


--
-- Name: clusters_one_authority_cluster_per_jurisdiction; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX clusters_one_authority_cluster_per_jurisdiction ON public.clusters USING btree (jurisdiction_id) WHERE ((deleted_at IS NULL) AND ((kind)::text = 'authority'::text));


--
-- Name: clusters_one_self; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX clusters_one_self ON public.clusters USING btree (is_self) WHERE ((deleted_at IS NULL) AND (is_self = true));


--
-- Name: committee_meetings_committee_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX committee_meetings_committee_id_index ON public.committee_meetings USING btree (committee_id);


--
-- Name: committee_reports_committee_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX committee_reports_committee_id_index ON public.committee_reports USING btree (committee_id);


--
-- Name: committee_seats_member_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX committee_seats_member_id_index ON public.committee_seats USING btree (member_id);


--
-- Name: committee_seats_one_live; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX committee_seats_one_live ON public.committee_seats USING btree (committee_id, member_id) WHERE (vacated_at IS NULL);


--
-- Name: committees_legislature_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX committees_legislature_id_index ON public.committees USING btree (legislature_id);


--
-- Name: constitutional_challenges_challenged_law_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX constitutional_challenges_challenged_law_id_status_index ON public.constitutional_challenges USING btree (challenged_law_id, status);


--
-- Name: constitutional_challenges_judiciary_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX constitutional_challenges_judiciary_id_status_index ON public.constitutional_challenges USING btree (judiciary_id, status);


--
-- Name: constitutional_findings_challenge_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX constitutional_findings_challenge_unique ON public.constitutional_findings USING btree (challenge_id) WHERE (deleted_at IS NULL);


--
-- Name: constitutional_settings_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX constitutional_settings_jurisdiction_id_index ON public.constitutional_settings USING btree (jurisdiction_id);


--
-- Name: cosmic_addresses_parent_id_sort_order_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cosmic_addresses_parent_id_sort_order_index ON public.cosmic_addresses USING btree (parent_id, sort_order);


--
-- Name: cosmic_addresses_type_enabled_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cosmic_addresses_type_enabled_index ON public.cosmic_addresses USING btree (type, enabled);


--
-- Name: cultural_institutions_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cultural_institutions_jurisdiction_id_index ON public.cultural_institutions USING btree (jurisdiction_id);


--
-- Name: data_review_decisions_category_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX data_review_decisions_category_jurisdiction_id_index ON public.data_review_decisions USING btree (category, jurisdiction_id);


--
-- Name: department_reports_department_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX department_reports_department_id_status_index ON public.department_reports USING btree (department_id, status);


--
-- Name: department_reports_due_on_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX department_reports_due_on_index ON public.department_reports USING btree (due_on);


--
-- Name: department_rules_department_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX department_rules_department_id_status_index ON public.department_rules USING btree (department_id, status);


--
-- Name: department_rules_enabling_type_enabling_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX department_rules_enabling_type_enabling_id_index ON public.department_rules USING btree (enabling_type, enabling_id);


--
-- Name: departments_executive_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX departments_executive_id_index ON public.departments USING btree (executive_id);


--
-- Name: departments_jurisdiction_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX departments_jurisdiction_id_status_index ON public.departments USING btree (jurisdiction_id, status);


--
-- Name: departments_one_named_kind; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX departments_one_named_kind ON public.departments USING btree (jurisdiction_id, kind) WHERE (((kind)::text <> 'other'::text) AND (deleted_at IS NULL));


--
-- Name: directory_entries_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX directory_entries_jurisdiction_id_index ON public.directory_entries USING btree (jurisdiction_id);


--
-- Name: directory_entries_jurisdiction_server_source_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX directory_entries_jurisdiction_server_source_unique ON public.directory_entries USING btree (jurisdiction_id, server_id, COALESCE(source_server_id, server_id)) WHERE (deleted_at IS NULL);


--
-- Name: directory_entries_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX directory_entries_server_id_index ON public.directory_entries USING btree (server_id);


--
-- Name: disintermediation_processes_intermediary_jurisdiction_id_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX disintermediation_processes_intermediary_jurisdiction_id_status ON public.disintermediation_processes USING btree (intermediary_jurisdiction_id, status);


--
-- Name: district_subdivisions_centroid_gist; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX district_subdivisions_centroid_gist ON public.district_subdivisions USING gist (centroid);


--
-- Name: district_subdivisions_geom_gist; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX district_subdivisions_geom_gist ON public.district_subdivisions USING gist (geom);


--
-- Name: district_subdivisions_map_id_parent_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX district_subdivisions_map_id_parent_jurisdiction_id_index ON public.district_subdivisions USING btree (map_id, parent_jurisdiction_id);


--
-- Name: district_subdivisions_map_label_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX district_subdivisions_map_label_unique ON public.district_subdivisions USING btree (map_id, label) WHERE (deleted_at IS NULL);


--
-- Name: district_subdivisions_parent_subdivision_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX district_subdivisions_parent_subdivision_id_index ON public.district_subdivisions USING btree (parent_subdivision_id);


--
-- Name: election_audits_election_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX election_audits_election_id_index ON public.election_audits USING btree (election_id);


--
-- Name: election_ballot_key_rewraps_election_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX election_ballot_key_rewraps_election_id_index ON public.election_ballot_key_rewraps USING btree (election_id);


--
-- Name: election_ballot_key_rewraps_to_cluster_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX election_ballot_key_rewraps_to_cluster_id_index ON public.election_ballot_key_rewraps USING btree (to_cluster_id);


--
-- Name: election_board_members_election_board_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX election_board_members_election_board_id_index ON public.election_board_members USING btree (election_board_id);


--
-- Name: election_board_members_one_seat; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX election_board_members_one_seat ON public.election_board_members USING btree (election_board_id, user_id) WHERE (((status)::text = 'seated'::text) AND (user_id IS NOT NULL));


--
-- Name: election_boards_legislature_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX election_boards_legislature_id_index ON public.election_boards USING btree (legislature_id);


--
-- Name: election_boards_one_active; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX election_boards_one_active ON public.election_boards USING btree (jurisdiction_id) WHERE (((status)::text = 'active'::text) AND (deleted_at IS NULL));


--
-- Name: election_certifications_election_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX election_certifications_election_id_index ON public.election_certifications USING btree (election_id);


--
-- Name: election_certifications_one_current; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX election_certifications_one_current ON public.election_certifications USING btree (election_id) WHERE ((status)::text = 'certified'::text);


--
-- Name: election_races_district_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX election_races_district_id_index ON public.election_races USING btree (district_id);


--
-- Name: election_races_election_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX election_races_election_id_status_index ON public.election_races USING btree (election_id, status);


--
-- Name: election_races_one_at_large_per_kind; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX election_races_one_at_large_per_kind ON public.election_races USING btree (election_id, seat_kind) WHERE (district_id IS NULL);


--
-- Name: elections_board_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX elections_board_id_status_index ON public.elections USING btree (board_id, status);


--
-- Name: elections_executive_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX elections_executive_id_status_index ON public.elections USING btree (executive_id, status);


--
-- Name: elections_finalist_cutoff_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX elections_finalist_cutoff_at_index ON public.elections USING btree (finalist_cutoff_at);


--
-- Name: elections_judiciary_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX elections_judiciary_id_status_index ON public.elections USING btree (judiciary_id, status);


--
-- Name: elections_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX elections_jurisdiction_id_index ON public.elections USING btree (jurisdiction_id);


--
-- Name: elections_jurisdiction_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX elections_jurisdiction_id_status_index ON public.elections USING btree (jurisdiction_id, status);


--
-- Name: elections_kind_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX elections_kind_status_index ON public.elections USING btree (kind, status);


--
-- Name: elections_legislature_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX elections_legislature_id_status_index ON public.elections USING btree (legislature_id, status);


--
-- Name: elections_ranked_closes_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX elections_ranked_closes_at_index ON public.elections USING btree (ranked_closes_at);


--
-- Name: elections_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX elections_status_index ON public.elections USING btree (status);


--
-- Name: emergency_power_reviews_emergency_power_id_outcome_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX emergency_power_reviews_emergency_power_id_outcome_index ON public.emergency_power_reviews USING btree (emergency_power_id, outcome);


--
-- Name: emergency_powers_area_jurisdiction_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX emergency_powers_area_jurisdiction_id_status_index ON public.emergency_powers USING btree (area_jurisdiction_id, status);


--
-- Name: emergency_powers_legislature_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX emergency_powers_legislature_id_status_index ON public.emergency_powers USING btree (legislature_id, status);


--
-- Name: endorsements_candidate_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX endorsements_candidate_id_index ON public.endorsements USING btree (candidate_id);


--
-- Name: endorsements_election_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX endorsements_election_id_index ON public.endorsements USING btree (election_id);


--
-- Name: endorsements_endorser_type_endorser_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX endorsements_endorser_type_endorser_id_index ON public.endorsements USING btree (endorser_type, endorser_id);


--
-- Name: executive_investigations_executive_id_outcome_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX executive_investigations_executive_id_outcome_index ON public.executive_investigations USING btree (executive_id, outcome);


--
-- Name: executive_members_executive_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX executive_members_executive_id_index ON public.executive_members USING btree (executive_id);


--
-- Name: executive_members_executive_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX executive_members_executive_id_status_index ON public.executive_members USING btree (executive_id, status);


--
-- Name: executive_members_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX executive_members_user_id_index ON public.executive_members USING btree (user_id);


--
-- Name: executive_orders_department_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX executive_orders_department_id_index ON public.executive_orders USING btree (department_id);


--
-- Name: executive_orders_executive_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX executive_orders_executive_id_status_index ON public.executive_orders USING btree (executive_id, status);


--
-- Name: executives_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX executives_jurisdiction_id_index ON public.executives USING btree (jurisdiction_id);


--
-- Name: executives_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX executives_status_index ON public.executives USING btree (status);


--
-- Name: federation_peers_server_id_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX federation_peers_server_id_unique ON public.federation_peers USING btree (server_id) WHERE (deleted_at IS NULL);


--
-- Name: federation_peers_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX federation_peers_status_index ON public.federation_peers USING btree (status);


--
-- Name: federation_transport_health_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX federation_transport_health_server_id_index ON public.federation_transport_health USING btree (server_id);


--
-- Name: federation_transport_health_server_transport_url_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX federation_transport_health_server_transport_url_unique ON public.federation_transport_health USING btree (server_id, transport, url) WHERE (deleted_at IS NULL);


--
-- Name: federation_transports_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX federation_transports_server_id_index ON public.federation_transports USING btree (server_id);


--
-- Name: federation_transports_server_transport_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX federation_transports_server_transport_unique ON public.federation_transports USING btree (server_id, transport) WHERE (deleted_at IS NULL);


--
-- Name: finding_offending_laws_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX finding_offending_laws_unique ON public.finding_offending_laws USING btree (finding_id, law_id);


--
-- Name: forwarded_writes_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX forwarded_writes_jurisdiction_id_index ON public.forwarded_writes USING btree (jurisdiction_id);


--
-- Name: forwarded_writes_origin_key_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX forwarded_writes_origin_key_unique ON public.forwarded_writes USING btree (origin_server_id, idempotency_key) WHERE (deleted_at IS NULL);


--
-- Name: forwarded_writes_origin_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX forwarded_writes_origin_server_id_index ON public.forwarded_writes USING btree (origin_server_id);


--
-- Name: foundation_sync_cursors_peer_id_table_name_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX foundation_sync_cursors_peer_id_table_name_status_index ON public.foundation_sync_cursors USING btree (peer_id, table_name, status);


--
-- Name: geoboundary_metadata_continent_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX geoboundary_metadata_continent_index ON public.geoboundary_metadata USING btree (continent);


--
-- Name: geoboundary_metadata_iso_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX geoboundary_metadata_iso_code_index ON public.geoboundary_metadata USING btree (iso_code);


--
-- Name: geoboundary_metadata_unsdg_region_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX geoboundary_metadata_unsdg_region_index ON public.geoboundary_metadata USING btree (unsdg_region);


--
-- Name: geoboundary_metadata_world_bank_income_group_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX geoboundary_metadata_world_bank_income_group_index ON public.geoboundary_metadata USING btree (world_bank_income_group);


--
-- Name: geodata_dataset_manifests_dataset_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX geodata_dataset_manifests_dataset_index ON public.geodata_dataset_manifests USING btree (dataset);


--
-- Name: geodata_dataset_manifests_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX geodata_dataset_manifests_unique ON public.geodata_dataset_manifests USING btree (dataset, version, origin_server_id) WHERE (deleted_at IS NULL);


--
-- Name: governor_removal_requests_board_seat_id_outcome_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX governor_removal_requests_board_seat_id_outcome_index ON public.governor_removal_requests USING btree (board_seat_id, outcome);


--
-- Name: grant_applications_appropriation_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX grant_applications_appropriation_id_status_index ON public.grant_applications USING btree (appropriation_id, status);


--
-- Name: grant_disbursements_application_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX grant_disbursements_application_id_index ON public.grant_disbursements USING btree (application_id);


--
-- Name: instance_capabilities_capability_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX instance_capabilities_capability_index ON public.instance_capabilities USING btree (capability);


--
-- Name: instance_capabilities_server_capability_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX instance_capabilities_server_capability_unique ON public.instance_capabilities USING btree (server_id, capability) WHERE (deleted_at IS NULL);


--
-- Name: instance_capabilities_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX instance_capabilities_server_id_index ON public.instance_capabilities USING btree (server_id);


--
-- Name: instance_settings_singleton_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX instance_settings_singleton_idx ON public.instance_settings USING btree ((1)) WHERE (deleted_at IS NULL);


--
-- Name: invites_handle_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX invites_handle_unique ON public.invites USING btree (handle) WHERE (deleted_at IS NULL);


--
-- Name: invites_inviter_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX invites_inviter_user_id_index ON public.invites USING btree (inviter_user_id);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: judicial_nominations_judiciary_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX judicial_nominations_judiciary_id_status_index ON public.judicial_nominations USING btree (judiciary_id, status);


--
-- Name: judicial_nominations_nominating_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX judicial_nominations_nominating_jurisdiction_id_index ON public.judicial_nominations USING btree (nominating_jurisdiction_id);


--
-- Name: judicial_seats_judiciary_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX judicial_seats_judiciary_id_index ON public.judicial_seats USING btree (judiciary_id);


--
-- Name: judicial_seats_judiciary_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX judicial_seats_judiciary_id_status_index ON public.judicial_seats USING btree (judiciary_id, status);


--
-- Name: judicial_seats_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX judicial_seats_user_id_index ON public.judicial_seats USING btree (user_id);


--
-- Name: judiciaries_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX judiciaries_jurisdiction_id_index ON public.judiciaries USING btree (jurisdiction_id);


--
-- Name: judiciaries_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX judiciaries_status_index ON public.judiciaries USING btree (status);


--
-- Name: juries_case_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX juries_case_unique ON public.juries USING btree (case_id) WHERE (deleted_at IS NULL);


--
-- Name: jurisdiction_activations_state_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jurisdiction_activations_state_index ON public.jurisdiction_activations USING btree (state);


--
-- Name: jurisdiction_maps_one_active_per_root; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX jurisdiction_maps_one_active_per_root ON public.jurisdiction_maps USING btree (root_jurisdiction_id) WHERE (((status)::text = 'active'::text) AND (deleted_at IS NULL));


--
-- Name: jurisdiction_maps_root_jurisdiction_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jurisdiction_maps_root_jurisdiction_id_status_index ON public.jurisdiction_maps USING btree (root_jurisdiction_id, status);


--
-- Name: jurisdictions_adm_level_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jurisdictions_adm_level_index ON public.jurisdictions USING btree (adm_level);


--
-- Name: jurisdictions_authoritative_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jurisdictions_authoritative_server_id_index ON public.jurisdictions USING btree (authoritative_server_id);


--
-- Name: jurisdictions_centroid_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jurisdictions_centroid_idx ON public.jurisdictions USING gist (centroid);


--
-- Name: jurisdictions_geom_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jurisdictions_geom_idx ON public.jurisdictions USING gist (geom);


--
-- Name: jurisdictions_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jurisdictions_is_active_index ON public.jurisdictions USING btree (is_active);


--
-- Name: jurisdictions_is_civic_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jurisdictions_is_civic_active_index ON public.jurisdictions USING btree (is_civic_active);


--
-- Name: jurisdictions_map_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jurisdictions_map_id_index ON public.jurisdictions USING btree (map_id);


--
-- Name: jurisdictions_parent_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jurisdictions_parent_id_index ON public.jurisdictions USING btree (parent_id);


--
-- Name: jury_members_jury_user_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX jury_members_jury_user_unique ON public.jury_members USING btree (jury_id, user_id) WHERE (deleted_at IS NULL);


--
-- Name: jury_members_user_id_screening_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jury_members_user_id_screening_status_index ON public.jury_members USING btree (user_id, screening_status);


--
-- Name: law_merge_resolutions_process_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX law_merge_resolutions_process_id_index ON public.law_merge_resolutions USING btree (process_id);


--
-- Name: laws_jurisdiction_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX laws_jurisdiction_id_status_index ON public.laws USING btree (jurisdiction_id, status);


--
-- Name: ldj_district_jurisdiction_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ldj_district_jurisdiction_unique ON public.legislature_district_jurisdictions USING btree (district_id, jurisdiction_id) WHERE (jurisdiction_id IS NOT NULL);


--
-- Name: ldj_district_subdivision_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ldj_district_subdivision_unique ON public.legislature_district_jurisdictions USING btree (district_id, subdivision_id) WHERE (subdivision_id IS NOT NULL);


--
-- Name: legal_compliance_removals_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legal_compliance_removals_jurisdiction_id_index ON public.legal_compliance_removals USING btree (jurisdiction_id);


--
-- Name: legal_compliance_removals_operator_account_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legal_compliance_removals_operator_account_id_index ON public.legal_compliance_removals USING btree (operator_account_id);


--
-- Name: legislature_district_jurisdictions_district_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legislature_district_jurisdictions_district_id_index ON public.legislature_district_jurisdictions USING btree (district_id);


--
-- Name: legislature_district_jurisdictions_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legislature_district_jurisdictions_jurisdiction_id_index ON public.legislature_district_jurisdictions USING btree (jurisdiction_id);


--
-- Name: legislature_district_jurisdictions_subdivision_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legislature_district_jurisdictions_subdivision_id_index ON public.legislature_district_jurisdictions USING btree (subdivision_id);


--
-- Name: legislature_district_maps_legislature_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legislature_district_maps_legislature_id_index ON public.legislature_district_maps USING btree (legislature_id);


--
-- Name: legislature_district_maps_legislature_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legislature_district_maps_legislature_id_status_index ON public.legislature_district_maps USING btree (legislature_id, status);


--
-- Name: legislature_districts_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legislature_districts_jurisdiction_id_index ON public.legislature_districts USING btree (jurisdiction_id);


--
-- Name: legislature_districts_legislature_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legislature_districts_legislature_id_index ON public.legislature_districts USING btree (legislature_id);


--
-- Name: legislature_districts_live_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX legislature_districts_live_unique ON public.legislature_districts USING btree (legislature_id, jurisdiction_id, district_number, map_id) WHERE (deleted_at IS NULL);


--
-- Name: legislature_districts_map_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legislature_districts_map_id_index ON public.legislature_districts USING btree (map_id);


--
-- Name: legislature_members_legislature_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legislature_members_legislature_id_index ON public.legislature_members USING btree (legislature_id);


--
-- Name: legislature_members_one_current; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX legislature_members_one_current ON public.legislature_members USING btree (legislature_id, user_id) WHERE (((status)::text = ANY ((ARRAY['elected'::character varying, 'seated'::character varying])::text[])) AND (deleted_at IS NULL));


--
-- Name: legislature_members_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legislature_members_status_index ON public.legislature_members USING btree (status);


--
-- Name: legislature_members_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legislature_members_user_id_index ON public.legislature_members USING btree (user_id);


--
-- Name: legislature_sessions_legislature_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legislature_sessions_legislature_id_status_index ON public.legislature_sessions USING btree (legislature_id, status);


--
-- Name: legislatures_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legislatures_jurisdiction_id_index ON public.legislatures USING btree (jurisdiction_id);


--
-- Name: legislatures_jurisdiction_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legislatures_jurisdiction_id_status_index ON public.legislatures USING btree (jurisdiction_id, status);


--
-- Name: legislatures_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legislatures_status_index ON public.legislatures USING btree (status);


--
-- Name: local_autonomy_processes_parent_process_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX local_autonomy_processes_parent_process_id_index ON public.local_autonomy_processes USING btree (parent_process_id);


--
-- Name: local_autonomy_processes_promoting_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX local_autonomy_processes_promoting_jurisdiction_id_index ON public.local_autonomy_processes USING btree (promoting_jurisdiction_id);


--
-- Name: location_pings_claim_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX location_pings_claim_id_index ON public.location_pings USING btree (claim_id);


--
-- Name: location_pings_geom_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX location_pings_geom_idx ON public.location_pings USING gist (geom);


--
-- Name: location_pings_pinged_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX location_pings_pinged_at_index ON public.location_pings USING btree (pinged_at);


--
-- Name: location_pings_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX location_pings_user_id_index ON public.location_pings USING btree (user_id);


--
-- Name: location_pings_user_id_pinged_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX location_pings_user_id_pinged_at_index ON public.location_pings USING btree (user_id, pinged_at);


--
-- Name: matrix_carveout_log_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX matrix_carveout_log_jurisdiction_id_index ON public.matrix_carveout_log USING btree (jurisdiction_id);


--
-- Name: matrix_carveout_log_matrix_room_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX matrix_carveout_log_matrix_room_id_index ON public.matrix_carveout_log USING btree (matrix_room_id);


--
-- Name: matrix_event_snapshots_event_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX matrix_event_snapshots_event_unique ON public.matrix_event_snapshots USING btree (matrix_event_id) WHERE (deleted_at IS NULL);


--
-- Name: matrix_event_snapshots_matrix_event_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX matrix_event_snapshots_matrix_event_id_index ON public.matrix_event_snapshots USING btree (matrix_event_id);


--
-- Name: matrix_event_snapshots_published_record_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX matrix_event_snapshots_published_record_id_index ON public.matrix_event_snapshots USING btree (published_record_id);


--
-- Name: matrix_identities_localpart_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX matrix_identities_localpart_unique ON public.matrix_identities USING btree (lower((matrix_localpart)::text)) WHERE (deleted_at IS NULL);


--
-- Name: matrix_identities_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX matrix_identities_user_id_index ON public.matrix_identities USING btree (user_id);


--
-- Name: matrix_identities_user_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX matrix_identities_user_unique ON public.matrix_identities USING btree (user_id) WHERE (deleted_at IS NULL);


--
-- Name: matrix_rooms_entity_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX matrix_rooms_entity_id_index ON public.matrix_rooms USING btree (entity_id);


--
-- Name: matrix_rooms_entity_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX matrix_rooms_entity_unique ON public.matrix_rooms USING btree (entity_type, entity_id, space_type) NULLS NOT DISTINCT WHERE (deleted_at IS NULL);


--
-- Name: matrix_rooms_matrix_room_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX matrix_rooms_matrix_room_id_index ON public.matrix_rooms USING btree (matrix_room_id);


--
-- Name: matrix_rooms_room_id_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX matrix_rooms_room_id_unique ON public.matrix_rooms USING btree (matrix_room_id) WHERE ((matrix_room_id IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: matrix_server_acls_matrix_room_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX matrix_server_acls_matrix_room_id_index ON public.matrix_server_acls USING btree (matrix_room_id);


--
-- Name: mesh_operator_identities_genesis_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX mesh_operator_identities_genesis_server_id_index ON public.mesh_operator_identities USING btree (genesis_server_id);


--
-- Name: mesh_operator_keys_identity_key_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX mesh_operator_keys_identity_key_unique ON public.mesh_operator_keys USING btree (mesh_operator_id, device_public_key) WHERE (deleted_at IS NULL);


--
-- Name: mesh_operator_keys_mesh_operator_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX mesh_operator_keys_mesh_operator_id_index ON public.mesh_operator_keys USING btree (mesh_operator_id);


--
-- Name: mesh_operator_local_links_account_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX mesh_operator_local_links_account_unique ON public.mesh_operator_local_links USING btree (operator_account_id) WHERE ((deleted_at IS NULL) AND (unlinked_at IS NULL));


--
-- Name: mesh_operator_local_links_mesh_operator_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX mesh_operator_local_links_mesh_operator_id_index ON public.mesh_operator_local_links USING btree (mesh_operator_id);


--
-- Name: mesh_operator_local_links_operator_account_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX mesh_operator_local_links_operator_account_id_index ON public.mesh_operator_local_links USING btree (operator_account_id);


--
-- Name: misconduct_investigations_subject_type_subject_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX misconduct_investigations_subject_type_subject_id_index ON public.misconduct_investigations USING btree (subject_type, subject_id);


--
-- Name: motions_bill_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX motions_bill_id_index ON public.motions USING btree (bill_id);


--
-- Name: motions_session_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX motions_session_id_status_index ON public.motions USING btree (session_id, status);


--
-- Name: multi_jurisdiction_votes_kind_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX multi_jurisdiction_votes_kind_status_index ON public.multi_jurisdiction_votes USING btree (kind, status);


--
-- Name: oidc_authorization_codes_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX oidc_authorization_codes_expires_at_index ON public.oidc_authorization_codes USING btree (expires_at);


--
-- Name: oidc_authorization_codes_hash_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX oidc_authorization_codes_hash_unique ON public.oidc_authorization_codes USING btree (code_hash);


--
-- Name: oidc_authorization_codes_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX oidc_authorization_codes_user_id_index ON public.oidc_authorization_codes USING btree (user_id);


--
-- Name: oidc_signing_keys_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX oidc_signing_keys_is_active_index ON public.oidc_signing_keys USING btree (is_active);


--
-- Name: oidc_signing_keys_kid_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX oidc_signing_keys_kid_unique ON public.oidc_signing_keys USING btree (kid) WHERE (deleted_at IS NULL);


--
-- Name: operational_partition_exports_peer_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX operational_partition_exports_peer_server_id_index ON public.operational_partition_exports USING btree (peer_server_id);


--
-- Name: operational_partition_exports_root_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX operational_partition_exports_root_jurisdiction_id_index ON public.operational_partition_exports USING btree (root_jurisdiction_id);


--
-- Name: operator_accounts_mesh_operator_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX operator_accounts_mesh_operator_id_index ON public.operator_accounts USING btree (mesh_operator_id);


--
-- Name: operator_accounts_server_username_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX operator_accounts_server_username_unique ON public.operator_accounts USING btree (server_id, username) WHERE (deleted_at IS NULL);


--
-- Name: operator_devices_operator_account_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX operator_devices_operator_account_id_index ON public.operator_devices USING btree (operator_account_id);


--
-- Name: operator_devices_public_key_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX operator_devices_public_key_unique ON public.operator_devices USING btree (device_public_key) WHERE (deleted_at IS NULL);


--
-- Name: opinion_law_links_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX opinion_law_links_unique ON public.opinion_law_links USING btree (opinion_id, law_id, law_version_no) WHERE (deleted_at IS NULL);


--
-- Name: opinions_case_id_kind_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX opinions_case_id_kind_index ON public.opinions USING btree (case_id, kind);


--
-- Name: org_contracts_counterparty_type_counterparty_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX org_contracts_counterparty_type_counterparty_id_index ON public.org_contracts USING btree (counterparty_type, counterparty_id);


--
-- Name: org_contracts_organization_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX org_contracts_organization_id_status_index ON public.org_contracts USING btree (organization_id, status);


--
-- Name: org_conversions_organization_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX org_conversions_organization_id_status_index ON public.org_conversions USING btree (organization_id, status);


--
-- Name: org_document_packages_key_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX org_document_packages_key_unique ON public.org_document_packages USING btree (organization_id, key) WHERE (deleted_at IS NULL);


--
-- Name: org_memberships_active_by_org_kind; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX org_memberships_active_by_org_kind ON public.org_memberships USING btree (organization_id, kind) WHERE ((status)::text = 'active'::text);


--
-- Name: org_memberships_active_by_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX org_memberships_active_by_user ON public.org_memberships USING btree (user_id) WHERE ((status)::text = 'active'::text);


--
-- Name: org_memberships_one_open_per_class; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX org_memberships_one_open_per_class ON public.org_memberships USING btree (organization_id, user_id, kind) WHERE (((status)::text = ANY ((ARRAY['applied'::character varying, 'active'::character varying])::text[])) AND (deleted_at IS NULL));


--
-- Name: org_ownership_stakes_open_by_holder; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX org_ownership_stakes_open_by_holder ON public.org_ownership_stakes USING btree (holder_type, holder_id) WHERE (ended_at IS NULL);


--
-- Name: org_ownership_stakes_open_by_org; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX org_ownership_stakes_open_by_org ON public.org_ownership_stakes USING btree (organization_id) WHERE (ended_at IS NULL);


--
-- Name: org_transfers_organization_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX org_transfers_organization_id_status_index ON public.org_transfers USING btree (organization_id, status);


--
-- Name: org_workers_active_by_employer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX org_workers_active_by_employer ON public.org_workers USING btree (employer_type, employer_id) WHERE ((status)::text = 'active'::text);


--
-- Name: org_workers_active_by_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX org_workers_active_by_user ON public.org_workers USING btree (user_id) WHERE ((status)::text = 'active'::text);


--
-- Name: org_workers_one_open_per_employer; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX org_workers_one_open_per_employer ON public.org_workers USING btree (employer_type, employer_id, user_id) WHERE (((status)::text = ANY ((ARRAY['applied'::character varying, 'active'::character varying])::text[])) AND (deleted_at IS NULL));


--
-- Name: organizations_active_by_jurisdiction; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organizations_active_by_jurisdiction ON public.organizations USING btree (jurisdiction_id) WHERE ((status)::text = 'active'::text);


--
-- Name: organizations_agent_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organizations_agent_user_id_index ON public.organizations USING btree (agent_user_id);


--
-- Name: organizations_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organizations_is_active_index ON public.organizations USING btree (is_active);


--
-- Name: organizations_is_cgc_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organizations_is_cgc_index ON public.organizations USING btree (is_cgc);


--
-- Name: organizations_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organizations_jurisdiction_id_index ON public.organizations USING btree (jurisdiction_id);


--
-- Name: organizations_ownership_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organizations_ownership_type_index ON public.organizations USING btree (ownership_type);


--
-- Name: organizations_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organizations_status_index ON public.organizations USING btree (status);


--
-- Name: organizations_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organizations_type_index ON public.organizations USING btree (type);


--
-- Name: panel_judges_one_presiding; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX panel_judges_one_presiding ON public.panel_judges USING btree (panel_id) WHERE (is_presiding AND ((status)::text = 'seated'::text) AND (deleted_at IS NULL));


--
-- Name: panel_judges_panel_seat_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX panel_judges_panel_seat_unique ON public.panel_judges USING btree (panel_id, judicial_seat_id) WHERE (deleted_at IS NULL);


--
-- Name: panels_case_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX panels_case_unique ON public.panels USING btree (case_id) WHERE (deleted_at IS NULL);


--
-- Name: partition_exports_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX partition_exports_jurisdiction_id_index ON public.partition_exports USING btree (jurisdiction_id);


--
-- Name: partition_exports_peer_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX partition_exports_peer_id_index ON public.partition_exports USING btree (peer_id);


--
-- Name: partition_exports_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX partition_exports_status_index ON public.partition_exports USING btree (status);


--
-- Name: peer_upgrade_consents_operator_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX peer_upgrade_consents_operator_unique ON public.peer_upgrade_consents USING btree (proposal_id, operator_account_id) WHERE ((operator_account_id IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: peer_upgrade_consents_peer_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX peer_upgrade_consents_peer_unique ON public.peer_upgrade_consents USING btree (proposal_id, peer_server_id) WHERE ((peer_server_id IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: peer_upgrade_consents_proposal_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX peer_upgrade_consents_proposal_id_index ON public.peer_upgrade_consents USING btree (proposal_id);


--
-- Name: peer_upgrade_proposals_affected_root_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX peer_upgrade_proposals_affected_root_jurisdiction_id_index ON public.peer_upgrade_proposals USING btree (affected_root_jurisdiction_id);


--
-- Name: peer_upgrade_proposals_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX peer_upgrade_proposals_status_index ON public.peer_upgrade_proposals USING btree (status);


--
-- Name: petition_signatures_one_live; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX petition_signatures_one_live ON public.petition_signatures USING btree (petition_id, user_id) WHERE (revoked_at IS NULL);


--
-- Name: petition_signatures_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX petition_signatures_user_id_index ON public.petition_signatures USING btree (user_id);


--
-- Name: petitions_jurisdiction_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX petitions_jurisdiction_id_status_index ON public.petitions USING btree (jurisdiction_id, status);


--
-- Name: policy_proposals_department_id_decision_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX policy_proposals_department_id_decision_index ON public.policy_proposals USING btree (department_id, decision);


--
-- Name: public_records_actor_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX public_records_actor_user_id_index ON public.public_records USING btree (actor_user_id);


--
-- Name: public_records_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX public_records_jurisdiction_id_index ON public.public_records USING btree (jurisdiction_id);


--
-- Name: public_records_kind_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX public_records_kind_index ON public.public_records USING btree (kind);


--
-- Name: public_records_legislature_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX public_records_legislature_id_index ON public.public_records USING btree (legislature_id);


--
-- Name: public_records_source_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX public_records_source_server_id_index ON public.public_records USING btree (source_server_id);


--
-- Name: public_records_subject_type_subject_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX public_records_subject_type_subject_id_index ON public.public_records USING btree (subject_type, subject_id);


--
-- Name: read_write_requests_applicant_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX read_write_requests_applicant_server_id_index ON public.read_write_requests USING btree (applicant_server_id);


--
-- Name: read_write_requests_open_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX read_write_requests_open_unique ON public.read_write_requests USING btree (applicant_server_id, root_jurisdiction_id) WHERE ((deleted_at IS NULL) AND ((status)::text = ANY ((ARRAY['submitted'::character varying, 'vote_opened'::character varying])::text[])));


--
-- Name: read_write_requests_root_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX read_write_requests_root_jurisdiction_id_index ON public.read_write_requests USING btree (root_jurisdiction_id);


--
-- Name: referendum_questions_election_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX referendum_questions_election_id_status_index ON public.referendum_questions USING btree (election_id, status);


--
-- Name: referendum_questions_jurisdiction_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX referendum_questions_jurisdiction_id_status_index ON public.referendum_questions USING btree (jurisdiction_id, status);


--
-- Name: remedy_recommendations_challenge_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX remedy_recommendations_challenge_unique ON public.remedy_recommendations USING btree (challenge_id) WHERE (deleted_at IS NULL);


--
-- Name: removal_proceedings_legislature_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX removal_proceedings_legislature_id_index ON public.removal_proceedings USING btree (legislature_id);


--
-- Name: removal_proceedings_subject_type_subject_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX removal_proceedings_subject_type_subject_id_index ON public.removal_proceedings USING btree (subject_type, subject_id);


--
-- Name: residency_claims_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX residency_claims_jurisdiction_id_index ON public.residency_claims USING btree (jurisdiction_id);


--
-- Name: residency_claims_one_open_per_user; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX residency_claims_one_open_per_user ON public.residency_claims USING btree (user_id) WHERE (((status)::text <> ALL ((ARRAY['superseded'::character varying, 'lapsed'::character varying])::text[])) AND (deleted_at IS NULL));


--
-- Name: residency_claims_user_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX residency_claims_user_id_status_index ON public.residency_claims USING btree (user_id, status);


--
-- Name: residency_confirmations_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX residency_confirmations_is_active_index ON public.residency_confirmations USING btree (is_active);


--
-- Name: residency_confirmations_jurisdiction_active_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX residency_confirmations_jurisdiction_active_idx ON public.residency_confirmations USING btree (jurisdiction_id) WHERE is_active;


--
-- Name: residency_confirmations_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX residency_confirmations_jurisdiction_id_index ON public.residency_confirmations USING btree (jurisdiction_id);


--
-- Name: residency_confirmations_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX residency_confirmations_user_id_index ON public.residency_confirmations USING btree (user_id);


--
-- Name: residency_confirmations_user_jur_active_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX residency_confirmations_user_jur_active_unique ON public.residency_confirmations USING btree (user_id, jurisdiction_id) WHERE is_active;


--
-- Name: restoration_events_jurisdiction_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX restoration_events_jurisdiction_id_status_index ON public.restoration_events USING btree (jurisdiction_id, status);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: setting_changes_jurisdiction_id_setting_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX setting_changes_jurisdiction_id_setting_key_index ON public.setting_changes USING btree (jurisdiction_id, setting_key);


--
-- Name: social_follows_follower_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX social_follows_follower_user_id_index ON public.social_follows USING btree (follower_user_id);


--
-- Name: social_follows_target_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX social_follows_target_id_index ON public.social_follows USING btree (target_id);


--
-- Name: social_follows_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX social_follows_unique ON public.social_follows USING btree (follower_user_id, target_type, target_id) WHERE (deleted_at IS NULL);


--
-- Name: social_memberships_block_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX social_memberships_block_user_id_index ON public.social_memberships USING btree (block_user_id);


--
-- Name: social_memberships_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX social_memberships_user_id_index ON public.social_memberships USING btree (user_id);


--
-- Name: social_posts_author_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX social_posts_author_user_id_index ON public.social_posts USING btree (author_user_id);


--
-- Name: social_profiles_handle_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX social_profiles_handle_unique ON public.social_profiles USING btree (lower((handle)::text)) WHERE ((handle IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: social_profiles_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX social_profiles_user_id_index ON public.social_profiles USING btree (user_id);


--
-- Name: social_profiles_user_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX social_profiles_user_unique ON public.social_profiles USING btree (user_id) WHERE (deleted_at IS NULL);


--
-- Name: social_reactions_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX social_reactions_unique ON public.social_reactions USING btree (post_id, user_id, kind) WHERE (deleted_at IS NULL);


--
-- Name: social_reactions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX social_reactions_user_id_index ON public.social_reactions USING btree (user_id);


--
-- Name: social_spaces_jur_type_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX social_spaces_jur_type_unique ON public.social_spaces USING btree (jurisdiction_id, space_type) WHERE ((is_private = false) AND (deleted_at IS NULL));


--
-- Name: social_spaces_jurisdiction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX social_spaces_jurisdiction_id_index ON public.social_spaces USING btree (jurisdiction_id);


--
-- Name: social_spaces_owner_org_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX social_spaces_owner_org_id_index ON public.social_spaces USING btree (owner_org_id);


--
-- Name: social_spaces_owner_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX social_spaces_owner_user_id_index ON public.social_spaces USING btree (owner_user_id);


--
-- Name: social_subforums_general_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX social_subforums_general_unique ON public.social_subforums USING btree (space_id) WHERE ((governing_object_type IS NULL) AND (deleted_at IS NULL));


--
-- Name: social_subforums_governing_object_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX social_subforums_governing_object_id_index ON public.social_subforums USING btree (governing_object_id);


--
-- Name: social_subforums_object_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX social_subforums_object_unique ON public.social_subforums USING btree (governing_object_type, governing_object_id) WHERE ((governing_object_type IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: social_threads_author_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX social_threads_author_user_id_index ON public.social_threads USING btree (author_user_id);


--
-- Name: social_threads_published_record_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX social_threads_published_record_id_index ON public.social_threads USING btree (published_record_id);


--
-- Name: standing_attestations_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX standing_attestations_expires_at_index ON public.standing_attestations USING btree (expires_at);


--
-- Name: standing_attestations_issuer_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX standing_attestations_issuer_server_id_index ON public.standing_attestations USING btree (issuer_server_id);


--
-- Name: standing_attestations_subject_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX standing_attestations_subject_user_id_index ON public.standing_attestations USING btree (subject_user_id);


--
-- Name: support_reports_category_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX support_reports_category_index ON public.support_reports USING btree (category);


--
-- Name: support_reports_reporter_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX support_reports_reporter_id_index ON public.support_reports USING btree (reporter_id);


--
-- Name: support_reports_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX support_reports_status_index ON public.support_reports USING btree (status);


--
-- Name: sync_cursors_one_open_cold_per_peer; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX sync_cursors_one_open_cold_per_peer ON public.sync_cursors USING btree (peer_id, direction) WHERE ((deleted_at IS NULL) AND ((mode)::text = 'cold'::text) AND ((status)::text = 'open'::text));


--
-- Name: sync_cursors_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sync_cursors_status_index ON public.sync_cursors USING btree (status);


--
-- Name: sync_log_direction_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sync_log_direction_index ON public.sync_log USING btree (direction);


--
-- Name: sync_log_peer_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sync_log_peer_id_index ON public.sync_log USING btree (peer_id);


--
-- Name: sync_log_result_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sync_log_result_index ON public.sync_log USING btree (result);


--
-- Name: tabulations_race_id_kind_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tabulations_race_id_kind_status_index ON public.tabulations USING btree (race_id, kind, status);


--
-- Name: terms_holder_user_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX terms_holder_user_id_status_index ON public.terms USING btree (holder_user_id, status);


--
-- Name: terms_legislature_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX terms_legislature_id_status_index ON public.terms USING btree (legislature_id, status);


--
-- Name: terms_office_type_office_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX terms_office_type_office_id_index ON public.terms USING btree (office_type, office_id);


--
-- Name: union_processes_kind_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX union_processes_kind_status_index ON public.union_processes USING btree (kind, status);


--
-- Name: users_home_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_home_server_id_index ON public.users USING btree (home_server_id);


--
-- Name: users_invited_by_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_invited_by_user_id_index ON public.users USING btree (invited_by_user_id);


--
-- Name: users_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_status_index ON public.users USING btree (status);


--
-- Name: vacancies_seat_type_seat_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vacancies_seat_type_seat_id_index ON public.vacancies USING btree (seat_type, seat_id);


--
-- Name: vacancies_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vacancies_status_index ON public.vacancies USING btree (status);


--
-- Name: verdicts_case_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX verdicts_case_unique ON public.verdicts USING btree (case_id) WHERE (deleted_at IS NULL);


--
-- Name: vote_casts_member_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vote_casts_member_id_index ON public.vote_casts USING btree (member_id);


--
-- Name: vote_casts_one_board_seat_cast; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX vote_casts_one_board_seat_cast ON public.vote_casts USING btree (vote_id, board_seat_id) WHERE (board_seat_id IS NOT NULL);


--
-- Name: vote_casts_one_member_cast; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX vote_casts_one_member_cast ON public.vote_casts USING btree (vote_id, member_id) WHERE (member_id IS NOT NULL);


--
-- Name: vote_casts_one_tiebreak; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX vote_casts_one_tiebreak ON public.vote_casts USING btree (vote_id) WHERE is_tiebreak;


--
-- Name: worldpop_rasters_gist; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX worldpop_rasters_gist ON public.worldpop_rasters USING gist (public.st_convexhull(rast));


--
-- Name: worldpop_rasters_iso_year; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX worldpop_rasters_iso_year ON public.worldpop_rasters USING btree (iso_code, year);


--
-- Name: achievements achievements_immutable; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER achievements_immutable BEFORE DELETE OR UPDATE ON public.achievements FOR EACH ROW EXECUTE FUNCTION public.achievements_block_mutation();


--
-- Name: achievements achievements_no_truncate; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER achievements_no_truncate BEFORE TRUNCATE ON public.achievements FOR EACH STATEMENT EXECUTE FUNCTION public.achievements_block_mutation();


--
-- Name: audit_checkpoints audit_checkpoints_immutable; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER audit_checkpoints_immutable BEFORE DELETE OR UPDATE ON public.audit_checkpoints FOR EACH ROW EXECUTE FUNCTION public.audit_checkpoints_block_mutation();


--
-- Name: audit_checkpoints audit_checkpoints_no_truncate; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER audit_checkpoints_no_truncate BEFORE TRUNCATE ON public.audit_checkpoints FOR EACH STATEMENT EXECUTE FUNCTION public.audit_checkpoints_block_mutation();


--
-- Name: audit_log audit_log_immutable; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER audit_log_immutable BEFORE DELETE OR UPDATE ON public.audit_log FOR EACH ROW EXECUTE FUNCTION public.audit_log_block_mutation();


--
-- Name: audit_log audit_log_no_truncate; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER audit_log_no_truncate BEFORE TRUNCATE ON public.audit_log FOR EACH STATEMENT EXECUTE FUNCTION public.audit_log_block_mutation();


--
-- Name: case_filings case_filings_immutable; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER case_filings_immutable BEFORE DELETE OR UPDATE ON public.case_filings FOR EACH ROW EXECUTE FUNCTION public.case_filings_block_mutation();


--
-- Name: case_filings case_filings_no_truncate; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER case_filings_no_truncate BEFORE TRUNCATE ON public.case_filings FOR EACH STATEMENT EXECUTE FUNCTION public.case_filings_block_mutation();


--
-- Name: cgc_ip_register cgc_ip_register_immutable; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER cgc_ip_register_immutable BEFORE DELETE OR UPDATE ON public.cgc_ip_register FOR EACH ROW EXECUTE FUNCTION public.cgc_ip_register_block_mutation();


--
-- Name: cgc_ip_register cgc_ip_register_no_truncate; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER cgc_ip_register_no_truncate BEFORE TRUNCATE ON public.cgc_ip_register FOR EACH STATEMENT EXECUTE FUNCTION public.cgc_ip_register_block_mutation();


--
-- Name: location_pings location_pings_set_geom; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER location_pings_set_geom BEFORE INSERT OR UPDATE ON public.location_pings FOR EACH ROW EXECUTE FUNCTION public.set_location_ping_geom();


--
-- Name: public_records public_records_immutable; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER public_records_immutable BEFORE DELETE OR UPDATE ON public.public_records FOR EACH ROW EXECUTE FUNCTION public.public_records_block_mutation();


--
-- Name: public_records public_records_no_truncate; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER public_records_no_truncate BEFORE TRUNCATE ON public.public_records FOR EACH STATEMENT EXECUTE FUNCTION public.public_records_block_mutation();


--
-- Name: sync_log sync_log_immutable; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER sync_log_immutable BEFORE DELETE OR UPDATE ON public.sync_log FOR EACH ROW EXECUTE FUNCTION public.sync_log_block_mutation();


--
-- Name: sync_log sync_log_no_truncate; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER sync_log_no_truncate BEFORE TRUNCATE ON public.sync_log FOR EACH STATEMENT EXECUTE FUNCTION public.sync_log_block_mutation();


--
-- Name: actor_devices actor_devices_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.actor_devices
    ADD CONSTRAINT actor_devices_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: admin_offices admin_offices_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_offices
    ADD CONSTRAINT admin_offices_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE RESTRICT;


--
-- Name: advocates advocates_judiciary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.advocates
    ADD CONSTRAINT advocates_judiciary_id_foreign FOREIGN KEY (judiciary_id) REFERENCES public.judiciaries(id) ON DELETE RESTRICT;


--
-- Name: advocates advocates_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.advocates
    ADD CONSTRAINT advocates_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: advocates advocates_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.advocates
    ADD CONSTRAINT advocates_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: appointments appointments_nominee_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_nominee_user_id_foreign FOREIGN KEY (nominee_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: appointments appointments_term_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_term_id_foreign FOREIGN KEY (term_id) REFERENCES public.terms(id) ON DELETE SET NULL;


--
-- Name: appropriations appropriations_executive_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appropriations
    ADD CONSTRAINT appropriations_executive_id_foreign FOREIGN KEY (executive_id) REFERENCES public.executives(id) ON DELETE RESTRICT;


--
-- Name: appropriations appropriations_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appropriations
    ADD CONSTRAINT appropriations_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: appropriations appropriations_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appropriations
    ADD CONSTRAINT appropriations_law_id_foreign FOREIGN KEY (law_id) REFERENCES public.laws(id) ON DELETE RESTRICT;


--
-- Name: approval_standings approval_standings_candidacy_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.approval_standings
    ADD CONSTRAINT approval_standings_candidacy_id_foreign FOREIGN KEY (candidacy_id) REFERENCES public.candidacies(id) ON DELETE CASCADE;


--
-- Name: approval_standings approval_standings_race_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.approval_standings
    ADD CONSTRAINT approval_standings_race_id_foreign FOREIGN KEY (race_id) REFERENCES public.election_races(id) ON DELETE CASCADE;


--
-- Name: approvals approvals_candidacy_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.approvals
    ADD CONSTRAINT approvals_candidacy_id_foreign FOREIGN KEY (candidacy_id) REFERENCES public.candidacies(id) ON DELETE CASCADE;


--
-- Name: approvals approvals_election_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.approvals
    ADD CONSTRAINT approvals_election_id_foreign FOREIGN KEY (election_id) REFERENCES public.elections(id) ON DELETE CASCADE;


--
-- Name: approvals approvals_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.approvals
    ADD CONSTRAINT approvals_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: authority_claims authority_claims_claimed_by_peer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authority_claims
    ADD CONSTRAINT authority_claims_claimed_by_peer_id_foreign FOREIGN KEY (claimed_by_peer_id) REFERENCES public.federation_peers(id) ON DELETE SET NULL;


--
-- Name: authority_claims authority_claims_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authority_claims
    ADD CONSTRAINT authority_claims_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: ballot_envelopes ballot_envelopes_race_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ballot_envelopes
    ADD CONSTRAINT ballot_envelopes_race_id_foreign FOREIGN KEY (race_id) REFERENCES public.election_races(id) ON DELETE CASCADE;


--
-- Name: ballot_envelopes ballot_envelopes_referendum_question_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ballot_envelopes
    ADD CONSTRAINT ballot_envelopes_referendum_question_id_foreign FOREIGN KEY (referendum_question_id) REFERENCES public.referendum_questions(id) ON DELETE RESTRICT;


--
-- Name: ballot_envelopes ballot_envelopes_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ballot_envelopes
    ADD CONSTRAINT ballot_envelopes_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: ballots ballots_race_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ballots
    ADD CONSTRAINT ballots_race_id_foreign FOREIGN KEY (race_id) REFERENCES public.election_races(id) ON DELETE CASCADE;


--
-- Name: ballots ballots_referendum_question_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ballots
    ADD CONSTRAINT ballots_referendum_question_id_foreign FOREIGN KEY (referendum_question_id) REFERENCES public.referendum_questions(id) ON DELETE RESTRICT;


--
-- Name: bill_versions bill_versions_bill_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bill_versions
    ADD CONSTRAINT bill_versions_bill_id_foreign FOREIGN KEY (bill_id) REFERENCES public.bills(id) ON DELETE CASCADE;


--
-- Name: bill_versions bill_versions_changed_by_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bill_versions
    ADD CONSTRAINT bill_versions_changed_by_member_id_foreign FOREIGN KEY (changed_by_member_id) REFERENCES public.legislature_members(id) ON DELETE SET NULL;


--
-- Name: bills bills_committee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bills
    ADD CONSTRAINT bills_committee_id_foreign FOREIGN KEY (committee_id) REFERENCES public.committees(id) ON DELETE SET NULL;


--
-- Name: bills bills_enacted_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bills
    ADD CONSTRAINT bills_enacted_law_id_foreign FOREIGN KEY (enacted_law_id) REFERENCES public.laws(id) ON DELETE SET NULL;


--
-- Name: bills bills_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bills
    ADD CONSTRAINT bills_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: bills bills_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bills
    ADD CONSTRAINT bills_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE RESTRICT;


--
-- Name: bills bills_scope_judiciary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bills
    ADD CONSTRAINT bills_scope_judiciary_id_foreign FOREIGN KEY (scope_judiciary_id) REFERENCES public.judiciaries(id) ON DELETE SET NULL;


--
-- Name: bills bills_sponsor_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bills
    ADD CONSTRAINT bills_sponsor_member_id_foreign FOREIGN KEY (sponsor_member_id) REFERENCES public.legislature_members(id) ON DELETE RESTRICT;


--
-- Name: bills bills_targets_challenge_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bills
    ADD CONSTRAINT bills_targets_challenge_id_foreign FOREIGN KEY (targets_challenge_id) REFERENCES public.constitutional_challenges(id) ON DELETE SET NULL;


--
-- Name: board_seats board_seats_appointment_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.board_seats
    ADD CONSTRAINT board_seats_appointment_id_foreign FOREIGN KEY (appointment_id) REFERENCES public.appointments(id) ON DELETE SET NULL;


--
-- Name: board_seats board_seats_board_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.board_seats
    ADD CONSTRAINT board_seats_board_id_foreign FOREIGN KEY (board_id) REFERENCES public.boards(id) ON DELETE CASCADE;


--
-- Name: board_seats board_seats_elected_in_race_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.board_seats
    ADD CONSTRAINT board_seats_elected_in_race_id_foreign FOREIGN KEY (elected_in_race_id) REFERENCES public.election_races(id) ON DELETE SET NULL;


--
-- Name: board_seats board_seats_holder_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.board_seats
    ADD CONSTRAINT board_seats_holder_user_id_foreign FOREIGN KEY (holder_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: board_seats board_seats_term_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.board_seats
    ADD CONSTRAINT board_seats_term_id_foreign FOREIGN KEY (term_id) REFERENCES public.terms(id) ON DELETE SET NULL;


--
-- Name: boards boards_chair_seat_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.boards
    ADD CONSTRAINT boards_chair_seat_id_foreign FOREIGN KEY (chair_seat_id) REFERENCES public.board_seats(id) ON DELETE SET NULL;


--
-- Name: border_settlements border_settlements_jurisdiction_a_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.border_settlements
    ADD CONSTRAINT border_settlements_jurisdiction_a_id_foreign FOREIGN KEY (jurisdiction_a_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: border_settlements border_settlements_jurisdiction_b_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.border_settlements
    ADD CONSTRAINT border_settlements_jurisdiction_b_id_foreign FOREIGN KEY (jurisdiction_b_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: candidacies candidacies_election_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.candidacies
    ADD CONSTRAINT candidacies_election_id_foreign FOREIGN KEY (election_id) REFERENCES public.elections(id) ON DELETE CASCADE;


--
-- Name: candidacies candidacies_race_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.candidacies
    ADD CONSTRAINT candidacies_race_id_foreign FOREIGN KEY (race_id) REFERENCES public.election_races(id) ON DELETE SET NULL;


--
-- Name: candidacies candidacies_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.candidacies
    ADD CONSTRAINT candidacies_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: candidacies candidacies_validated_by_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.candidacies
    ADD CONSTRAINT candidacies_validated_by_member_id_foreign FOREIGN KEY (validated_by_member_id) REFERENCES public.election_board_members(id) ON DELETE SET NULL;


--
-- Name: case_filings case_filings_advocate_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_filings
    ADD CONSTRAINT case_filings_advocate_id_foreign FOREIGN KEY (advocate_id) REFERENCES public.advocates(id) ON DELETE SET NULL;


--
-- Name: case_filings case_filings_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_filings
    ADD CONSTRAINT case_filings_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE RESTRICT;


--
-- Name: case_filings case_filings_filed_by_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_filings
    ADD CONSTRAINT case_filings_filed_by_user_id_fkey FOREIGN KEY (filed_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: case_parties case_parties_case_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_parties
    ADD CONSTRAINT case_parties_case_id_foreign FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE CASCADE;


--
-- Name: case_parties case_parties_party_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_parties
    ADD CONSTRAINT case_parties_party_user_id_foreign FOREIGN KEY (party_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: case_parties case_parties_represented_by_advocate_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_parties
    ADD CONSTRAINT case_parties_represented_by_advocate_id_foreign FOREIGN KEY (represented_by_advocate_id) REFERENCES public.advocates(id) ON DELETE SET NULL;


--
-- Name: cases cases_advocate_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cases
    ADD CONSTRAINT cases_advocate_id_foreign FOREIGN KEY (advocate_id) REFERENCES public.advocates(id) ON DELETE SET NULL;


--
-- Name: cases cases_appeal_of_case_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cases
    ADD CONSTRAINT cases_appeal_of_case_id_foreign FOREIGN KEY (appeal_of_case_id) REFERENCES public.cases(id) ON DELETE SET NULL;


--
-- Name: cases cases_filed_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cases
    ADD CONSTRAINT cases_filed_by_user_id_foreign FOREIGN KEY (filed_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: cases cases_filed_on_behalf_of_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cases
    ADD CONSTRAINT cases_filed_on_behalf_of_user_id_foreign FOREIGN KEY (filed_on_behalf_of_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: cases cases_judiciary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cases
    ADD CONSTRAINT cases_judiciary_id_foreign FOREIGN KEY (judiciary_id) REFERENCES public.judiciaries(id) ON DELETE RESTRICT;


--
-- Name: cases cases_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cases
    ADD CONSTRAINT cases_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: cases cases_jury_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cases
    ADD CONSTRAINT cases_jury_id_foreign FOREIGN KEY (jury_id) REFERENCES public.juries(id) ON DELETE SET NULL;


--
-- Name: cases cases_panel_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cases
    ADD CONSTRAINT cases_panel_id_foreign FOREIGN KEY (panel_id) REFERENCES public.panels(id) ON DELETE SET NULL;


--
-- Name: cgc_ip_register cgc_ip_register_organization_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cgc_ip_register
    ADD CONSTRAINT cgc_ip_register_organization_id_fkey FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE RESTRICT;


--
-- Name: chamber_vote_proposals chamber_vote_proposals_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chamber_vote_proposals
    ADD CONSTRAINT chamber_vote_proposals_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE CASCADE;


--
-- Name: chamber_vote_proposals chamber_vote_proposals_proposed_by_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chamber_vote_proposals
    ADD CONSTRAINT chamber_vote_proposals_proposed_by_member_id_foreign FOREIGN KEY (proposed_by_member_id) REFERENCES public.legislature_members(id) ON DELETE SET NULL;


--
-- Name: chamber_vote_tallies chamber_vote_tallies_vote_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chamber_vote_tallies
    ADD CONSTRAINT chamber_vote_tallies_vote_id_foreign FOREIGN KEY (vote_id) REFERENCES public.chamber_votes(id) ON DELETE CASCADE;


--
-- Name: chamber_votes chamber_votes_held_in_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chamber_votes
    ADD CONSTRAINT chamber_votes_held_in_session_id_foreign FOREIGN KEY (held_in_session_id) REFERENCES public.legislature_sessions(id) ON DELETE SET NULL;


--
-- Name: chamber_votes chamber_votes_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chamber_votes
    ADD CONSTRAINT chamber_votes_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: chamber_votes chamber_votes_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chamber_votes
    ADD CONSTRAINT chamber_votes_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE RESTRICT;


--
-- Name: chamber_votes chamber_votes_opened_by_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chamber_votes
    ADD CONSTRAINT chamber_votes_opened_by_member_id_foreign FOREIGN KEY (opened_by_member_id) REFERENCES public.legislature_members(id) ON DELETE SET NULL;


--
-- Name: clock_timers clock_timers_clock_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clock_timers
    ADD CONSTRAINT clock_timers_clock_id_foreign FOREIGN KEY (clock_id) REFERENCES public.clocks(id) ON DELETE RESTRICT;


--
-- Name: cluster_members cluster_members_cluster_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cluster_members
    ADD CONSTRAINT cluster_members_cluster_id_foreign FOREIGN KEY (cluster_id) REFERENCES public.clusters(id) ON DELETE CASCADE;


--
-- Name: cluster_memberships cluster_memberships_peer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cluster_memberships
    ADD CONSTRAINT cluster_memberships_peer_id_foreign FOREIGN KEY (peer_id) REFERENCES public.federation_peers(id) ON DELETE CASCADE;


--
-- Name: committee_meetings committee_meetings_called_by_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committee_meetings
    ADD CONSTRAINT committee_meetings_called_by_member_id_foreign FOREIGN KEY (called_by_member_id) REFERENCES public.legislature_members(id) ON DELETE RESTRICT;


--
-- Name: committee_meetings committee_meetings_committee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committee_meetings
    ADD CONSTRAINT committee_meetings_committee_id_foreign FOREIGN KEY (committee_id) REFERENCES public.committees(id) ON DELETE CASCADE;


--
-- Name: committee_preferences committee_preferences_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committee_preferences
    ADD CONSTRAINT committee_preferences_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE CASCADE;


--
-- Name: committee_preferences committee_preferences_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committee_preferences
    ADD CONSTRAINT committee_preferences_member_id_foreign FOREIGN KEY (member_id) REFERENCES public.legislature_members(id) ON DELETE CASCADE;


--
-- Name: committee_reports committee_reports_bill_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committee_reports
    ADD CONSTRAINT committee_reports_bill_id_foreign FOREIGN KEY (bill_id) REFERENCES public.bills(id) ON DELETE SET NULL;


--
-- Name: committee_reports committee_reports_committee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committee_reports
    ADD CONSTRAINT committee_reports_committee_id_foreign FOREIGN KEY (committee_id) REFERENCES public.committees(id) ON DELETE CASCADE;


--
-- Name: committee_reports committee_reports_filed_by_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committee_reports
    ADD CONSTRAINT committee_reports_filed_by_member_id_foreign FOREIGN KEY (filed_by_member_id) REFERENCES public.legislature_members(id) ON DELETE RESTRICT;


--
-- Name: committee_seats committee_seats_committee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committee_seats
    ADD CONSTRAINT committee_seats_committee_id_foreign FOREIGN KEY (committee_id) REFERENCES public.committees(id) ON DELETE CASCADE;


--
-- Name: committee_seats committee_seats_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committee_seats
    ADD CONSTRAINT committee_seats_member_id_foreign FOREIGN KEY (member_id) REFERENCES public.legislature_members(id) ON DELETE RESTRICT;


--
-- Name: committees committees_alternate_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committees
    ADD CONSTRAINT committees_alternate_member_id_foreign FOREIGN KEY (alternate_member_id) REFERENCES public.legislature_members(id) ON DELETE SET NULL;


--
-- Name: committees committees_chair_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committees
    ADD CONSTRAINT committees_chair_member_id_foreign FOREIGN KEY (chair_member_id) REFERENCES public.legislature_members(id) ON DELETE SET NULL;


--
-- Name: committees committees_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.committees
    ADD CONSTRAINT committees_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE RESTRICT;


--
-- Name: constituent_consents constituent_consents_chamber_vote_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constituent_consents
    ADD CONSTRAINT constituent_consents_chamber_vote_id_foreign FOREIGN KEY (chamber_vote_id) REFERENCES public.chamber_votes(id) ON DELETE SET NULL;


--
-- Name: constituent_consents constituent_consents_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constituent_consents
    ADD CONSTRAINT constituent_consents_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: constituent_consents constituent_consents_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constituent_consents
    ADD CONSTRAINT constituent_consents_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE SET NULL;


--
-- Name: constituent_consents constituent_consents_process_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constituent_consents
    ADD CONSTRAINT constituent_consents_process_id_foreign FOREIGN KEY (process_id) REFERENCES public.multi_jurisdiction_votes(id) ON DELETE CASCADE;


--
-- Name: constitutional_challenges constitutional_challenges_case_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_challenges
    ADD CONSTRAINT constitutional_challenges_case_id_foreign FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE SET NULL;


--
-- Name: constitutional_challenges constitutional_challenges_challenged_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_challenges
    ADD CONSTRAINT constitutional_challenges_challenged_law_id_foreign FOREIGN KEY (challenged_law_id) REFERENCES public.laws(id) ON DELETE RESTRICT;


--
-- Name: constitutional_challenges constitutional_challenges_cited_authority_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_challenges
    ADD CONSTRAINT constitutional_challenges_cited_authority_law_id_foreign FOREIGN KEY (cited_authority_law_id) REFERENCES public.laws(id) ON DELETE SET NULL;


--
-- Name: constitutional_challenges constitutional_challenges_filed_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_challenges
    ADD CONSTRAINT constitutional_challenges_filed_by_user_id_foreign FOREIGN KEY (filed_by_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: constitutional_challenges constitutional_challenges_finding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_challenges
    ADD CONSTRAINT constitutional_challenges_finding_id_foreign FOREIGN KEY (finding_id) REFERENCES public.constitutional_findings(id) ON DELETE SET NULL;


--
-- Name: constitutional_challenges constitutional_challenges_judiciary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_challenges
    ADD CONSTRAINT constitutional_challenges_judiciary_id_foreign FOREIGN KEY (judiciary_id) REFERENCES public.judiciaries(id) ON DELETE RESTRICT;


--
-- Name: constitutional_challenges constitutional_challenges_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_challenges
    ADD CONSTRAINT constitutional_challenges_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: constitutional_challenges constitutional_challenges_remedy_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_challenges
    ADD CONSTRAINT constitutional_challenges_remedy_id_foreign FOREIGN KEY (remedy_id) REFERENCES public.remedy_recommendations(id) ON DELETE SET NULL;


--
-- Name: constitutional_findings constitutional_findings_case_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_findings
    ADD CONSTRAINT constitutional_findings_case_id_foreign FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE SET NULL;


--
-- Name: constitutional_findings constitutional_findings_challenge_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_findings
    ADD CONSTRAINT constitutional_findings_challenge_id_foreign FOREIGN KEY (challenge_id) REFERENCES public.constitutional_challenges(id) ON DELETE CASCADE;


--
-- Name: constitutional_findings constitutional_findings_judiciary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_findings
    ADD CONSTRAINT constitutional_findings_judiciary_id_foreign FOREIGN KEY (judiciary_id) REFERENCES public.judiciaries(id) ON DELETE RESTRICT;


--
-- Name: constitutional_findings constitutional_findings_offending_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_findings
    ADD CONSTRAINT constitutional_findings_offending_law_id_foreign FOREIGN KEY (offending_law_id) REFERENCES public.laws(id) ON DELETE RESTRICT;


--
-- Name: constitutional_findings constitutional_findings_superior_authority_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_findings
    ADD CONSTRAINT constitutional_findings_superior_authority_law_id_foreign FOREIGN KEY (superior_authority_law_id) REFERENCES public.laws(id) ON DELETE SET NULL;


--
-- Name: constitutional_settings constitutional_settings_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_settings
    ADD CONSTRAINT constitutional_settings_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE CASCADE;


--
-- Name: constitutional_settings constitutional_settings_last_amended_by_act_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constitutional_settings
    ADD CONSTRAINT constitutional_settings_last_amended_by_act_id_foreign FOREIGN KEY (last_amended_by_act_id) REFERENCES public.laws(id) ON DELETE SET NULL;


--
-- Name: cosmic_addresses cosmic_addresses_parent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cosmic_addresses
    ADD CONSTRAINT cosmic_addresses_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES public.cosmic_addresses(id) ON DELETE CASCADE;


--
-- Name: cultural_institutions cultural_institutions_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cultural_institutions
    ADD CONSTRAINT cultural_institutions_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE CASCADE;


--
-- Name: cultural_institutions cultural_institutions_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cultural_institutions
    ADD CONSTRAINT cultural_institutions_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE SET NULL;


--
-- Name: data_review_decisions data_review_decisions_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_review_decisions
    ADD CONSTRAINT data_review_decisions_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE CASCADE;


--
-- Name: department_reports department_reports_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.department_reports
    ADD CONSTRAINT department_reports_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE RESTRICT;


--
-- Name: department_reports department_reports_filed_by_seat_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.department_reports
    ADD CONSTRAINT department_reports_filed_by_seat_id_foreign FOREIGN KEY (filed_by_seat_id) REFERENCES public.board_seats(id) ON DELETE SET NULL;


--
-- Name: department_rules department_rules_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.department_rules
    ADD CONSTRAINT department_rules_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE RESTRICT;


--
-- Name: department_rules department_rules_filed_by_seat_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.department_rules
    ADD CONSTRAINT department_rules_filed_by_seat_id_foreign FOREIGN KEY (filed_by_seat_id) REFERENCES public.board_seats(id) ON DELETE RESTRICT;


--
-- Name: department_rules department_rules_supersedes_rule_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.department_rules
    ADD CONSTRAINT department_rules_supersedes_rule_id_foreign FOREIGN KEY (supersedes_rule_id) REFERENCES public.department_rules(id) ON DELETE SET NULL;


--
-- Name: departments departments_board_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_board_id_foreign FOREIGN KEY (board_id) REFERENCES public.boards(id) ON DELETE SET NULL;


--
-- Name: departments departments_charter_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_charter_law_id_foreign FOREIGN KEY (charter_law_id) REFERENCES public.laws(id) ON DELETE RESTRICT;


--
-- Name: departments departments_executive_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_executive_id_foreign FOREIGN KEY (executive_id) REFERENCES public.executives(id) ON DELETE RESTRICT;


--
-- Name: departments departments_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: disintermediation_processes disintermediation_processes_encompassing_jurisdiction_id_foreig; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.disintermediation_processes
    ADD CONSTRAINT disintermediation_processes_encompassing_jurisdiction_id_foreig FOREIGN KEY (encompassing_jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: disintermediation_processes disintermediation_processes_intermediary_jurisdiction_id_foreig; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.disintermediation_processes
    ADD CONSTRAINT disintermediation_processes_intermediary_jurisdiction_id_foreig FOREIGN KEY (intermediary_jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: district_subdivisions district_subdivisions_map_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.district_subdivisions
    ADD CONSTRAINT district_subdivisions_map_id_foreign FOREIGN KEY (map_id) REFERENCES public.legislature_district_maps(id) ON DELETE CASCADE;


--
-- Name: district_subdivisions district_subdivisions_parent_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.district_subdivisions
    ADD CONSTRAINT district_subdivisions_parent_jurisdiction_id_foreign FOREIGN KEY (parent_jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE CASCADE;


--
-- Name: district_subdivisions district_subdivisions_parent_subdivision_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.district_subdivisions
    ADD CONSTRAINT district_subdivisions_parent_subdivision_id_foreign FOREIGN KEY (parent_subdivision_id) REFERENCES public.district_subdivisions(id) ON DELETE CASCADE;


--
-- Name: election_audits election_audits_election_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_audits
    ADD CONSTRAINT election_audits_election_id_foreign FOREIGN KEY (election_id) REFERENCES public.elections(id) ON DELETE CASCADE;


--
-- Name: election_audits election_audits_ordered_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_audits
    ADD CONSTRAINT election_audits_ordered_by_foreign FOREIGN KEY (ordered_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: election_audits election_audits_race_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_audits
    ADD CONSTRAINT election_audits_race_id_foreign FOREIGN KEY (race_id) REFERENCES public.election_races(id) ON DELETE SET NULL;


--
-- Name: election_audits election_audits_tabulation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_audits
    ADD CONSTRAINT election_audits_tabulation_id_foreign FOREIGN KEY (tabulation_id) REFERENCES public.tabulations(id) ON DELETE SET NULL;


--
-- Name: election_board_members election_board_members_appointment_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_board_members
    ADD CONSTRAINT election_board_members_appointment_id_foreign FOREIGN KEY (appointment_id) REFERENCES public.appointments(id) ON DELETE SET NULL;


--
-- Name: election_board_members election_board_members_election_board_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_board_members
    ADD CONSTRAINT election_board_members_election_board_id_foreign FOREIGN KEY (election_board_id) REFERENCES public.election_boards(id) ON DELETE CASCADE;


--
-- Name: election_board_members election_board_members_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_board_members
    ADD CONSTRAINT election_board_members_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: election_boards election_boards_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_boards
    ADD CONSTRAINT election_boards_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE CASCADE;


--
-- Name: election_boards election_boards_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_boards
    ADD CONSTRAINT election_boards_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE SET NULL;


--
-- Name: election_certifications election_certifications_certified_by_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_certifications
    ADD CONSTRAINT election_certifications_certified_by_member_id_foreign FOREIGN KEY (certified_by_member_id) REFERENCES public.election_board_members(id) ON DELETE SET NULL;


--
-- Name: election_certifications election_certifications_election_board_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_certifications
    ADD CONSTRAINT election_certifications_election_board_id_foreign FOREIGN KEY (election_board_id) REFERENCES public.election_boards(id) ON DELETE RESTRICT;


--
-- Name: election_certifications election_certifications_election_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_certifications
    ADD CONSTRAINT election_certifications_election_id_foreign FOREIGN KEY (election_id) REFERENCES public.elections(id) ON DELETE CASCADE;


--
-- Name: election_races election_races_district_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_races
    ADD CONSTRAINT election_races_district_id_foreign FOREIGN KEY (district_id) REFERENCES public.legislature_districts(id) ON DELETE RESTRICT;


--
-- Name: election_races election_races_election_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_races
    ADD CONSTRAINT election_races_election_id_foreign FOREIGN KEY (election_id) REFERENCES public.elections(id) ON DELETE CASCADE;


--
-- Name: election_races election_races_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.election_races
    ADD CONSTRAINT election_races_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE CASCADE;


--
-- Name: elections elections_board_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.elections
    ADD CONSTRAINT elections_board_id_foreign FOREIGN KEY (board_id) REFERENCES public.boards(id) ON DELETE RESTRICT;


--
-- Name: elections elections_district_map_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.elections
    ADD CONSTRAINT elections_district_map_id_foreign FOREIGN KEY (district_map_id) REFERENCES public.legislature_district_maps(id) ON DELETE RESTRICT;


--
-- Name: elections elections_election_board_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.elections
    ADD CONSTRAINT elections_election_board_id_foreign FOREIGN KEY (election_board_id) REFERENCES public.election_boards(id) ON DELETE SET NULL;


--
-- Name: elections elections_executive_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.elections
    ADD CONSTRAINT elections_executive_id_foreign FOREIGN KEY (executive_id) REFERENCES public.executives(id) ON DELETE SET NULL;


--
-- Name: elections elections_judiciary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.elections
    ADD CONSTRAINT elections_judiciary_id_foreign FOREIGN KEY (judiciary_id) REFERENCES public.judiciaries(id) ON DELETE SET NULL;


--
-- Name: elections elections_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.elections
    ADD CONSTRAINT elections_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE CASCADE;


--
-- Name: elections elections_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.elections
    ADD CONSTRAINT elections_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE SET NULL;


--
-- Name: elections elections_prior_election_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.elections
    ADD CONSTRAINT elections_prior_election_id_foreign FOREIGN KEY (prior_election_id) REFERENCES public.elections(id) ON DELETE SET NULL;


--
-- Name: elections elections_triggered_by_timer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.elections
    ADD CONSTRAINT elections_triggered_by_timer_id_foreign FOREIGN KEY (triggered_by_timer_id) REFERENCES public.clock_timers(id) ON DELETE SET NULL;


--
-- Name: elections elections_vacancy_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.elections
    ADD CONSTRAINT elections_vacancy_id_foreign FOREIGN KEY (vacancy_id) REFERENCES public.vacancies(id) ON DELETE SET NULL;


--
-- Name: emergency_power_renewals emergency_power_renewals_emergency_power_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emergency_power_renewals
    ADD CONSTRAINT emergency_power_renewals_emergency_power_id_foreign FOREIGN KEY (emergency_power_id) REFERENCES public.emergency_powers(id) ON DELETE CASCADE;


--
-- Name: emergency_power_renewals emergency_power_renewals_vote_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emergency_power_renewals
    ADD CONSTRAINT emergency_power_renewals_vote_id_foreign FOREIGN KEY (vote_id) REFERENCES public.chamber_votes(id) ON DELETE RESTRICT;


--
-- Name: emergency_power_reviews emergency_power_reviews_case_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emergency_power_reviews
    ADD CONSTRAINT emergency_power_reviews_case_id_foreign FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE SET NULL;


--
-- Name: emergency_power_reviews emergency_power_reviews_challenge_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emergency_power_reviews
    ADD CONSTRAINT emergency_power_reviews_challenge_id_foreign FOREIGN KEY (challenge_id) REFERENCES public.constitutional_challenges(id) ON DELETE SET NULL;


--
-- Name: emergency_power_reviews emergency_power_reviews_emergency_power_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emergency_power_reviews
    ADD CONSTRAINT emergency_power_reviews_emergency_power_id_foreign FOREIGN KEY (emergency_power_id) REFERENCES public.emergency_powers(id) ON DELETE RESTRICT;


--
-- Name: emergency_power_reviews emergency_power_reviews_judiciary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emergency_power_reviews
    ADD CONSTRAINT emergency_power_reviews_judiciary_id_foreign FOREIGN KEY (judiciary_id) REFERENCES public.judiciaries(id) ON DELETE RESTRICT;


--
-- Name: emergency_power_reviews emergency_power_reviews_narrowed_area_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emergency_power_reviews
    ADD CONSTRAINT emergency_power_reviews_narrowed_area_jurisdiction_id_foreign FOREIGN KEY (narrowed_area_jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE SET NULL;


--
-- Name: emergency_powers emergency_powers_area_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emergency_powers
    ADD CONSTRAINT emergency_powers_area_jurisdiction_id_foreign FOREIGN KEY (area_jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: emergency_powers emergency_powers_invoke_vote_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emergency_powers
    ADD CONSTRAINT emergency_powers_invoke_vote_id_foreign FOREIGN KEY (invoke_vote_id) REFERENCES public.chamber_votes(id) ON DELETE RESTRICT;


--
-- Name: emergency_powers emergency_powers_judicial_review_case_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emergency_powers
    ADD CONSTRAINT emergency_powers_judicial_review_case_id_foreign FOREIGN KEY (judicial_review_case_id) REFERENCES public.cases(id) ON DELETE SET NULL;


--
-- Name: emergency_powers emergency_powers_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emergency_powers
    ADD CONSTRAINT emergency_powers_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: emergency_powers emergency_powers_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emergency_powers
    ADD CONSTRAINT emergency_powers_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE RESTRICT;


--
-- Name: endorsement_requests endorsement_requests_candidacy_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.endorsement_requests
    ADD CONSTRAINT endorsement_requests_candidacy_id_foreign FOREIGN KEY (candidacy_id) REFERENCES public.candidacies(id) ON DELETE CASCADE;


--
-- Name: endorsement_requests endorsement_requests_endorsement_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.endorsement_requests
    ADD CONSTRAINT endorsement_requests_endorsement_id_foreign FOREIGN KEY (endorsement_id) REFERENCES public.endorsements(id) ON DELETE SET NULL;


--
-- Name: endorsement_requests endorsement_requests_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.endorsement_requests
    ADD CONSTRAINT endorsement_requests_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: endorsements endorsements_candidate_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.endorsements
    ADD CONSTRAINT endorsements_candidate_id_foreign FOREIGN KEY (candidate_id) REFERENCES public.candidacies(id) ON DELETE CASCADE;


--
-- Name: endorsements endorsements_election_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.endorsements
    ADD CONSTRAINT endorsements_election_id_foreign FOREIGN KEY (election_id) REFERENCES public.elections(id) ON DELETE CASCADE;


--
-- Name: executive_investigations executive_investigations_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executive_investigations
    ADD CONSTRAINT executive_investigations_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: executive_investigations executive_investigations_executive_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executive_investigations
    ADD CONSTRAINT executive_investigations_executive_id_foreign FOREIGN KEY (executive_id) REFERENCES public.executives(id) ON DELETE RESTRICT;


--
-- Name: executive_investigations executive_investigations_ordered_by_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executive_investigations
    ADD CONSTRAINT executive_investigations_ordered_by_member_id_foreign FOREIGN KEY (ordered_by_member_id) REFERENCES public.executive_members(id) ON DELETE RESTRICT;


--
-- Name: executive_members executive_members_elected_in_race_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executive_members
    ADD CONSTRAINT executive_members_elected_in_race_id_foreign FOREIGN KEY (elected_in_race_id) REFERENCES public.election_races(id) ON DELETE SET NULL;


--
-- Name: executive_members executive_members_executive_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executive_members
    ADD CONSTRAINT executive_members_executive_id_foreign FOREIGN KEY (executive_id) REFERENCES public.executives(id) ON DELETE CASCADE;


--
-- Name: executive_members executive_members_legislature_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executive_members
    ADD CONSTRAINT executive_members_legislature_member_id_foreign FOREIGN KEY (legislature_member_id) REFERENCES public.legislature_members(id) ON DELETE SET NULL;


--
-- Name: executive_members executive_members_term_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executive_members
    ADD CONSTRAINT executive_members_term_id_foreign FOREIGN KEY (term_id) REFERENCES public.terms(id) ON DELETE SET NULL;


--
-- Name: executive_members executive_members_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executive_members
    ADD CONSTRAINT executive_members_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: executive_orders executive_orders_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executive_orders
    ADD CONSTRAINT executive_orders_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: executive_orders executive_orders_executive_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executive_orders
    ADD CONSTRAINT executive_orders_executive_id_foreign FOREIGN KEY (executive_id) REFERENCES public.executives(id) ON DELETE RESTRICT;


--
-- Name: executive_orders executive_orders_issued_by_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executive_orders
    ADD CONSTRAINT executive_orders_issued_by_member_id_foreign FOREIGN KEY (issued_by_member_id) REFERENCES public.executive_members(id) ON DELETE RESTRICT;


--
-- Name: executives executives_conversion_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executives
    ADD CONSTRAINT executives_conversion_law_id_foreign FOREIGN KEY (conversion_law_id) REFERENCES public.laws(id) ON DELETE SET NULL;


--
-- Name: executives executives_conversion_process_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executives
    ADD CONSTRAINT executives_conversion_process_id_foreign FOREIGN KEY (conversion_process_id) REFERENCES public.multi_jurisdiction_votes(id) ON DELETE SET NULL;


--
-- Name: executives executives_delegation_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executives
    ADD CONSTRAINT executives_delegation_law_id_foreign FOREIGN KEY (delegation_law_id) REFERENCES public.laws(id) ON DELETE SET NULL;


--
-- Name: executives executives_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executives
    ADD CONSTRAINT executives_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE CASCADE;


--
-- Name: executives executives_parent_executive_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executives
    ADD CONSTRAINT executives_parent_executive_id_foreign FOREIGN KEY (parent_executive_id) REFERENCES public.executives(id) ON DELETE SET NULL;


--
-- Name: executives executives_source_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.executives
    ADD CONSTRAINT executives_source_legislature_id_foreign FOREIGN KEY (source_legislature_id) REFERENCES public.legislatures(id) ON DELETE SET NULL;


--
-- Name: finding_offending_laws finding_offending_laws_finding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.finding_offending_laws
    ADD CONSTRAINT finding_offending_laws_finding_id_foreign FOREIGN KEY (finding_id) REFERENCES public.constitutional_findings(id) ON DELETE CASCADE;


--
-- Name: finding_offending_laws finding_offending_laws_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.finding_offending_laws
    ADD CONSTRAINT finding_offending_laws_law_id_foreign FOREIGN KEY (law_id) REFERENCES public.laws(id) ON DELETE RESTRICT;


--
-- Name: governor_removal_requests governor_removal_requests_board_seat_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.governor_removal_requests
    ADD CONSTRAINT governor_removal_requests_board_seat_id_foreign FOREIGN KEY (board_seat_id) REFERENCES public.board_seats(id) ON DELETE RESTRICT;


--
-- Name: governor_removal_requests governor_removal_requests_requested_by_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.governor_removal_requests
    ADD CONSTRAINT governor_removal_requests_requested_by_member_id_foreign FOREIGN KEY (requested_by_member_id) REFERENCES public.executive_members(id) ON DELETE RESTRICT;


--
-- Name: governor_removal_requests governor_removal_requests_vote_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.governor_removal_requests
    ADD CONSTRAINT governor_removal_requests_vote_id_foreign FOREIGN KEY (vote_id) REFERENCES public.chamber_votes(id) ON DELETE SET NULL;


--
-- Name: grant_applications grant_applications_applicant_org_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grant_applications
    ADD CONSTRAINT grant_applications_applicant_org_id_foreign FOREIGN KEY (applicant_org_id) REFERENCES public.organizations(id) ON DELETE RESTRICT;


--
-- Name: grant_applications grant_applications_appropriation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grant_applications
    ADD CONSTRAINT grant_applications_appropriation_id_foreign FOREIGN KEY (appropriation_id) REFERENCES public.appropriations(id) ON DELETE RESTRICT;


--
-- Name: grant_applications grant_applications_decided_by_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grant_applications
    ADD CONSTRAINT grant_applications_decided_by_member_id_foreign FOREIGN KEY (decided_by_member_id) REFERENCES public.executive_members(id) ON DELETE SET NULL;


--
-- Name: grant_disbursements grant_disbursements_application_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grant_disbursements
    ADD CONSTRAINT grant_disbursements_application_id_foreign FOREIGN KEY (application_id) REFERENCES public.grant_applications(id) ON DELETE RESTRICT;


--
-- Name: grant_disbursements grant_disbursements_disbursed_by_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grant_disbursements
    ADD CONSTRAINT grant_disbursements_disbursed_by_member_id_foreign FOREIGN KEY (disbursed_by_member_id) REFERENCES public.executive_members(id) ON DELETE RESTRICT;


--
-- Name: instance_settings instance_settings_cosmic_address_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.instance_settings
    ADD CONSTRAINT instance_settings_cosmic_address_id_foreign FOREIGN KEY (cosmic_address_id) REFERENCES public.cosmic_addresses(id) ON DELETE SET NULL;


--
-- Name: invites invites_inviter_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invites
    ADD CONSTRAINT invites_inviter_user_id_foreign FOREIGN KEY (inviter_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: journey_progress journey_progress_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journey_progress
    ADD CONSTRAINT journey_progress_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: judicial_nominations judicial_nominations_appointment_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_nominations
    ADD CONSTRAINT judicial_nominations_appointment_id_foreign FOREIGN KEY (appointment_id) REFERENCES public.appointments(id) ON DELETE SET NULL;


--
-- Name: judicial_nominations judicial_nominations_judiciary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_nominations
    ADD CONSTRAINT judicial_nominations_judiciary_id_foreign FOREIGN KEY (judiciary_id) REFERENCES public.judiciaries(id) ON DELETE CASCADE;


--
-- Name: judicial_nominations judicial_nominations_nominating_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_nominations
    ADD CONSTRAINT judicial_nominations_nominating_jurisdiction_id_foreign FOREIGN KEY (nominating_jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE SET NULL;


--
-- Name: judicial_nominations judicial_nominations_nominee_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_nominations
    ADD CONSTRAINT judicial_nominations_nominee_user_id_foreign FOREIGN KEY (nominee_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: judicial_nominations judicial_nominations_seat_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_nominations
    ADD CONSTRAINT judicial_nominations_seat_id_foreign FOREIGN KEY (seat_id) REFERENCES public.judicial_seats(id) ON DELETE SET NULL;


--
-- Name: judicial_seats judicial_seats_appointment_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_seats
    ADD CONSTRAINT judicial_seats_appointment_id_foreign FOREIGN KEY (appointment_id) REFERENCES public.appointments(id) ON DELETE SET NULL;


--
-- Name: judicial_seats judicial_seats_elected_in_race_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_seats
    ADD CONSTRAINT judicial_seats_elected_in_race_id_foreign FOREIGN KEY (elected_in_race_id) REFERENCES public.election_races(id) ON DELETE SET NULL;


--
-- Name: judicial_seats judicial_seats_judiciary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_seats
    ADD CONSTRAINT judicial_seats_judiciary_id_foreign FOREIGN KEY (judiciary_id) REFERENCES public.judiciaries(id) ON DELETE CASCADE;


--
-- Name: judicial_seats judicial_seats_nominating_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_seats
    ADD CONSTRAINT judicial_seats_nominating_jurisdiction_id_foreign FOREIGN KEY (nominating_jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE SET NULL;


--
-- Name: judicial_seats judicial_seats_term_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_seats
    ADD CONSTRAINT judicial_seats_term_id_foreign FOREIGN KEY (term_id) REFERENCES public.terms(id) ON DELETE SET NULL;


--
-- Name: judicial_seats judicial_seats_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_seats
    ADD CONSTRAINT judicial_seats_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: judiciaries judiciaries_conversion_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judiciaries
    ADD CONSTRAINT judiciaries_conversion_law_id_foreign FOREIGN KEY (conversion_law_id) REFERENCES public.laws(id) ON DELETE SET NULL;


--
-- Name: judiciaries judiciaries_conversion_process_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judiciaries
    ADD CONSTRAINT judiciaries_conversion_process_id_foreign FOREIGN KEY (conversion_process_id) REFERENCES public.multi_jurisdiction_votes(id) ON DELETE SET NULL;


--
-- Name: judiciaries judiciaries_creation_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judiciaries
    ADD CONSTRAINT judiciaries_creation_law_id_foreign FOREIGN KEY (creation_law_id) REFERENCES public.laws(id) ON DELETE SET NULL;


--
-- Name: judiciaries judiciaries_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judiciaries
    ADD CONSTRAINT judiciaries_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE CASCADE;


--
-- Name: judiciaries judiciaries_parent_judiciary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judiciaries
    ADD CONSTRAINT judiciaries_parent_judiciary_id_foreign FOREIGN KEY (parent_judiciary_id) REFERENCES public.judiciaries(id) ON DELETE SET NULL;


--
-- Name: judiciaries judiciaries_source_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judiciaries
    ADD CONSTRAINT judiciaries_source_legislature_id_foreign FOREIGN KEY (source_legislature_id) REFERENCES public.legislatures(id) ON DELETE SET NULL;


--
-- Name: juries juries_case_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.juries
    ADD CONSTRAINT juries_case_id_foreign FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE CASCADE;


--
-- Name: juries juries_eligible_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.juries
    ADD CONSTRAINT juries_eligible_jurisdiction_id_foreign FOREIGN KEY (eligible_jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: jurisdiction_activations jurisdiction_activations_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_activations
    ADD CONSTRAINT jurisdiction_activations_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE CASCADE;


--
-- Name: jurisdiction_activations jurisdiction_activations_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_activations
    ADD CONSTRAINT jurisdiction_activations_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE SET NULL;


--
-- Name: jurisdiction_maps jurisdiction_maps_root_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_maps
    ADD CONSTRAINT jurisdiction_maps_root_jurisdiction_id_foreign FOREIGN KEY (root_jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE CASCADE;


--
-- Name: jurisdictions jurisdictions_parent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdictions
    ADD CONSTRAINT jurisdictions_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES public.jurisdictions(id) ON DELETE SET NULL;


--
-- Name: jury_members jury_members_jury_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jury_members
    ADD CONSTRAINT jury_members_jury_id_foreign FOREIGN KEY (jury_id) REFERENCES public.juries(id) ON DELETE CASCADE;


--
-- Name: jury_members jury_members_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jury_members
    ADD CONSTRAINT jury_members_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: law_merge_resolutions law_merge_resolutions_process_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.law_merge_resolutions
    ADD CONSTRAINT law_merge_resolutions_process_id_foreign FOREIGN KEY (process_id) REFERENCES public.disintermediation_processes(id) ON DELETE CASCADE;


--
-- Name: law_versions law_versions_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.law_versions
    ADD CONSTRAINT law_versions_law_id_foreign FOREIGN KEY (law_id) REFERENCES public.laws(id) ON DELETE CASCADE;


--
-- Name: laws laws_enacting_bill_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.laws
    ADD CONSTRAINT laws_enacting_bill_id_foreign FOREIGN KEY (enacting_bill_id) REFERENCES public.bills(id) ON DELETE SET NULL;


--
-- Name: laws laws_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.laws
    ADD CONSTRAINT laws_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: laws laws_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.laws
    ADD CONSTRAINT laws_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE RESTRICT;


--
-- Name: laws laws_scope_judiciary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.laws
    ADD CONSTRAINT laws_scope_judiciary_id_foreign FOREIGN KEY (scope_judiciary_id) REFERENCES public.judiciaries(id) ON DELETE SET NULL;


--
-- Name: laws laws_shield_expires_with_election_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.laws
    ADD CONSTRAINT laws_shield_expires_with_election_id_foreign FOREIGN KEY (shield_expires_with_election_id) REFERENCES public.elections(id) ON DELETE SET NULL;


--
-- Name: legislature_district_jurisdictions legislature_district_jurisdictions_district_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_district_jurisdictions
    ADD CONSTRAINT legislature_district_jurisdictions_district_id_foreign FOREIGN KEY (district_id) REFERENCES public.legislature_districts(id) ON DELETE CASCADE;


--
-- Name: legislature_district_jurisdictions legislature_district_jurisdictions_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_district_jurisdictions
    ADD CONSTRAINT legislature_district_jurisdictions_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE CASCADE;


--
-- Name: legislature_district_jurisdictions legislature_district_jurisdictions_subdivision_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_district_jurisdictions
    ADD CONSTRAINT legislature_district_jurisdictions_subdivision_id_foreign FOREIGN KEY (subdivision_id) REFERENCES public.district_subdivisions(id) ON DELETE CASCADE;


--
-- Name: legislature_district_maps legislature_district_maps_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_district_maps
    ADD CONSTRAINT legislature_district_maps_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE CASCADE;


--
-- Name: legislature_districts legislature_districts_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_districts
    ADD CONSTRAINT legislature_districts_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE CASCADE;


--
-- Name: legislature_districts legislature_districts_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_districts
    ADD CONSTRAINT legislature_districts_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE CASCADE;


--
-- Name: legislature_districts legislature_districts_map_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_districts
    ADD CONSTRAINT legislature_districts_map_id_foreign FOREIGN KEY (map_id) REFERENCES public.legislature_district_maps(id) ON DELETE SET NULL;


--
-- Name: legislature_members legislature_members_district_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_members
    ADD CONSTRAINT legislature_members_district_id_foreign FOREIGN KEY (district_id) REFERENCES public.legislature_districts(id) ON DELETE SET NULL;


--
-- Name: legislature_members legislature_members_elected_in_race_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_members
    ADD CONSTRAINT legislature_members_elected_in_race_id_foreign FOREIGN KEY (elected_in_race_id) REFERENCES public.election_races(id) ON DELETE SET NULL;


--
-- Name: legislature_members legislature_members_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_members
    ADD CONSTRAINT legislature_members_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE CASCADE;


--
-- Name: legislature_members legislature_members_term_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_members
    ADD CONSTRAINT legislature_members_term_id_foreign FOREIGN KEY (term_id) REFERENCES public.terms(id) ON DELETE SET NULL;


--
-- Name: legislature_members legislature_members_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_members
    ADD CONSTRAINT legislature_members_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: legislature_sessions legislature_sessions_called_by_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_sessions
    ADD CONSTRAINT legislature_sessions_called_by_member_id_foreign FOREIGN KEY (called_by_member_id) REFERENCES public.legislature_members(id) ON DELETE SET NULL;


--
-- Name: legislature_sessions legislature_sessions_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislature_sessions
    ADD CONSTRAINT legislature_sessions_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE RESTRICT;


--
-- Name: legislatures legislatures_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislatures
    ADD CONSTRAINT legislatures_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE CASCADE;


--
-- Name: legislatures legislatures_parent_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legislatures
    ADD CONSTRAINT legislatures_parent_legislature_id_foreign FOREIGN KEY (parent_legislature_id) REFERENCES public.legislatures(id) ON DELETE SET NULL;


--
-- Name: location_pings location_pings_claim_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.location_pings
    ADD CONSTRAINT location_pings_claim_id_foreign FOREIGN KEY (claim_id) REFERENCES public.residency_claims(id) ON DELETE SET NULL;


--
-- Name: location_pings location_pings_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.location_pings
    ADD CONSTRAINT location_pings_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: misconduct_investigations misconduct_investigations_admin_office_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.misconduct_investigations
    ADD CONSTRAINT misconduct_investigations_admin_office_id_foreign FOREIGN KEY (admin_office_id) REFERENCES public.admin_offices(id) ON DELETE CASCADE;


--
-- Name: misconduct_investigations misconduct_investigations_complainant_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.misconduct_investigations
    ADD CONSTRAINT misconduct_investigations_complainant_user_id_foreign FOREIGN KEY (complainant_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: misconduct_investigations misconduct_investigations_referred_proceeding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.misconduct_investigations
    ADD CONSTRAINT misconduct_investigations_referred_proceeding_id_foreign FOREIGN KEY (referred_proceeding_id) REFERENCES public.removal_proceedings(id) ON DELETE SET NULL;


--
-- Name: motions motions_bill_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.motions
    ADD CONSTRAINT motions_bill_id_foreign FOREIGN KEY (bill_id) REFERENCES public.bills(id) ON DELETE SET NULL;


--
-- Name: motions motions_moved_by_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.motions
    ADD CONSTRAINT motions_moved_by_member_id_foreign FOREIGN KEY (moved_by_member_id) REFERENCES public.legislature_members(id) ON DELETE RESTRICT;


--
-- Name: motions motions_seconded_by_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.motions
    ADD CONSTRAINT motions_seconded_by_member_id_foreign FOREIGN KEY (seconded_by_member_id) REFERENCES public.legislature_members(id) ON DELETE SET NULL;


--
-- Name: motions motions_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.motions
    ADD CONSTRAINT motions_session_id_foreign FOREIGN KEY (session_id) REFERENCES public.legislature_sessions(id) ON DELETE CASCADE;


--
-- Name: motions motions_vote_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.motions
    ADD CONSTRAINT motions_vote_id_foreign FOREIGN KEY (vote_id) REFERENCES public.chamber_votes(id) ON DELETE SET NULL;


--
-- Name: multi_jurisdiction_votes multi_jurisdiction_votes_initiating_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.multi_jurisdiction_votes
    ADD CONSTRAINT multi_jurisdiction_votes_initiating_legislature_id_foreign FOREIGN KEY (initiating_legislature_id) REFERENCES public.legislatures(id) ON DELETE RESTRICT;


--
-- Name: multi_jurisdiction_votes multi_jurisdiction_votes_initiating_vote_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.multi_jurisdiction_votes
    ADD CONSTRAINT multi_jurisdiction_votes_initiating_vote_id_foreign FOREIGN KEY (initiating_vote_id) REFERENCES public.chamber_votes(id) ON DELETE SET NULL;


--
-- Name: opinion_law_links opinion_law_links_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.opinion_law_links
    ADD CONSTRAINT opinion_law_links_law_id_foreign FOREIGN KEY (law_id) REFERENCES public.laws(id) ON DELETE RESTRICT;


--
-- Name: opinion_law_links opinion_law_links_opinion_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.opinion_law_links
    ADD CONSTRAINT opinion_law_links_opinion_id_foreign FOREIGN KEY (opinion_id) REFERENCES public.opinions(id) ON DELETE CASCADE;


--
-- Name: opinions opinions_authored_by_seat_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.opinions
    ADD CONSTRAINT opinions_authored_by_seat_id_foreign FOREIGN KEY (authored_by_seat_id) REFERENCES public.judicial_seats(id) ON DELETE RESTRICT;


--
-- Name: opinions opinions_case_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.opinions
    ADD CONSTRAINT opinions_case_id_foreign FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE CASCADE;


--
-- Name: opinions opinions_panel_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.opinions
    ADD CONSTRAINT opinions_panel_id_foreign FOREIGN KEY (panel_id) REFERENCES public.panels(id) ON DELETE RESTRICT;


--
-- Name: org_contracts org_contracts_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_contracts
    ADD CONSTRAINT org_contracts_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: org_contracts org_contracts_signed_by_org_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_contracts
    ADD CONSTRAINT org_contracts_signed_by_org_user_id_foreign FOREIGN KEY (signed_by_org_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: org_conversions org_conversions_authorizing_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_conversions
    ADD CONSTRAINT org_conversions_authorizing_law_id_foreign FOREIGN KEY (authorizing_law_id) REFERENCES public.laws(id) ON DELETE RESTRICT;


--
-- Name: org_conversions org_conversions_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_conversions
    ADD CONSTRAINT org_conversions_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: org_document_package_versions org_document_package_versions_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_document_package_versions
    ADD CONSTRAINT org_document_package_versions_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: org_document_package_versions org_document_package_versions_package_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_document_package_versions
    ADD CONSTRAINT org_document_package_versions_package_id_foreign FOREIGN KEY (package_id) REFERENCES public.org_document_packages(id) ON DELETE CASCADE;


--
-- Name: org_document_packages org_document_packages_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_document_packages
    ADD CONSTRAINT org_document_packages_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: org_memberships org_memberships_accepted_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_memberships
    ADD CONSTRAINT org_memberships_accepted_by_user_id_foreign FOREIGN KEY (accepted_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: org_memberships org_memberships_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_memberships
    ADD CONSTRAINT org_memberships_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: org_memberships org_memberships_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_memberships
    ADD CONSTRAINT org_memberships_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: org_ownership_stakes org_ownership_stakes_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_ownership_stakes
    ADD CONSTRAINT org_ownership_stakes_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: org_ownership_stakes org_ownership_stakes_source_transfer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_ownership_stakes
    ADD CONSTRAINT org_ownership_stakes_source_transfer_id_foreign FOREIGN KEY (source_transfer_id) REFERENCES public.org_transfers(id) ON DELETE SET NULL;


--
-- Name: org_transfers org_transfers_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_transfers
    ADD CONSTRAINT org_transfers_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: org_workers org_workers_contract_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_workers
    ADD CONSTRAINT org_workers_contract_id_foreign FOREIGN KEY (contract_id) REFERENCES public.org_contracts(id) ON DELETE SET NULL;


--
-- Name: org_workers org_workers_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_workers
    ADD CONSTRAINT org_workers_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: organizations organizations_agent_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_agent_user_id_foreign FOREIGN KEY (agent_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: organizations organizations_board_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_board_id_foreign FOREIGN KEY (board_id) REFERENCES public.boards(id) ON DELETE SET NULL;


--
-- Name: organizations organizations_created_by_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_created_by_law_id_foreign FOREIGN KEY (created_by_law_id) REFERENCES public.laws(id) ON DELETE RESTRICT;


--
-- Name: organizations organizations_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE CASCADE;


--
-- Name: organizations organizations_parent_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_parent_organization_id_foreign FOREIGN KEY (parent_organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: organizations organizations_registered_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_registered_by_user_id_foreign FOREIGN KEY (registered_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: panel_judges panel_judges_judicial_seat_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.panel_judges
    ADD CONSTRAINT panel_judges_judicial_seat_id_foreign FOREIGN KEY (judicial_seat_id) REFERENCES public.judicial_seats(id) ON DELETE RESTRICT;


--
-- Name: panel_judges panel_judges_panel_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.panel_judges
    ADD CONSTRAINT panel_judges_panel_id_foreign FOREIGN KEY (panel_id) REFERENCES public.panels(id) ON DELETE CASCADE;


--
-- Name: panel_judges panel_judges_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.panel_judges
    ADD CONSTRAINT panel_judges_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: panels panels_case_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.panels
    ADD CONSTRAINT panels_case_id_foreign FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE CASCADE;


--
-- Name: panels panels_judiciary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.panels
    ADD CONSTRAINT panels_judiciary_id_foreign FOREIGN KEY (judiciary_id) REFERENCES public.judiciaries(id) ON DELETE RESTRICT;


--
-- Name: panels panels_presiding_judge_seat_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.panels
    ADD CONSTRAINT panels_presiding_judge_seat_id_foreign FOREIGN KEY (presiding_judge_seat_id) REFERENCES public.judicial_seats(id) ON DELETE SET NULL;


--
-- Name: partition_exports partition_exports_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.partition_exports
    ADD CONSTRAINT partition_exports_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: partition_exports partition_exports_peer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.partition_exports
    ADD CONSTRAINT partition_exports_peer_id_foreign FOREIGN KEY (peer_id) REFERENCES public.federation_peers(id) ON DELETE SET NULL;


--
-- Name: petition_signatures petition_signatures_petition_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.petition_signatures
    ADD CONSTRAINT petition_signatures_petition_id_foreign FOREIGN KEY (petition_id) REFERENCES public.petitions(id) ON DELETE CASCADE;


--
-- Name: petition_signatures petition_signatures_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.petition_signatures
    ADD CONSTRAINT petition_signatures_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: petitions petitions_creator_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.petitions
    ADD CONSTRAINT petitions_creator_user_id_foreign FOREIGN KEY (creator_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: petitions petitions_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.petitions
    ADD CONSTRAINT petitions_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: petitions petitions_referendum_question_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.petitions
    ADD CONSTRAINT petitions_referendum_question_id_foreign FOREIGN KEY (referendum_question_id) REFERENCES public.referendum_questions(id) ON DELETE SET NULL;


--
-- Name: petitions petitions_review_case_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.petitions
    ADD CONSTRAINT petitions_review_case_id_foreign FOREIGN KEY (review_case_id) REFERENCES public.cases(id) ON DELETE SET NULL;


--
-- Name: petitions petitions_scope_judiciary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.petitions
    ADD CONSTRAINT petitions_scope_judiciary_id_foreign FOREIGN KEY (scope_judiciary_id) REFERENCES public.judiciaries(id) ON DELETE SET NULL;


--
-- Name: policy_proposals policy_proposals_board_vote_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.policy_proposals
    ADD CONSTRAINT policy_proposals_board_vote_id_foreign FOREIGN KEY (board_vote_id) REFERENCES public.chamber_votes(id) ON DELETE SET NULL;


--
-- Name: policy_proposals policy_proposals_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.policy_proposals
    ADD CONSTRAINT policy_proposals_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE RESTRICT;


--
-- Name: policy_proposals policy_proposals_executive_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.policy_proposals
    ADD CONSTRAINT policy_proposals_executive_id_foreign FOREIGN KEY (executive_id) REFERENCES public.executives(id) ON DELETE RESTRICT;


--
-- Name: policy_proposals policy_proposals_proposed_by_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.policy_proposals
    ADD CONSTRAINT policy_proposals_proposed_by_member_id_foreign FOREIGN KEY (proposed_by_member_id) REFERENCES public.executive_members(id) ON DELETE RESTRICT;


--
-- Name: race_results race_results_candidacy_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.race_results
    ADD CONSTRAINT race_results_candidacy_id_foreign FOREIGN KEY (candidacy_id) REFERENCES public.candidacies(id) ON DELETE RESTRICT;


--
-- Name: race_results race_results_tabulation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.race_results
    ADD CONSTRAINT race_results_tabulation_id_foreign FOREIGN KEY (tabulation_id) REFERENCES public.tabulations(id) ON DELETE CASCADE;


--
-- Name: referendum_questions referendum_questions_delegating_vote_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.referendum_questions
    ADD CONSTRAINT referendum_questions_delegating_vote_id_foreign FOREIGN KEY (delegating_vote_id) REFERENCES public.chamber_votes(id) ON DELETE RESTRICT;


--
-- Name: referendum_questions referendum_questions_election_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.referendum_questions
    ADD CONSTRAINT referendum_questions_election_id_foreign FOREIGN KEY (election_id) REFERENCES public.elections(id) ON DELETE RESTRICT;


--
-- Name: referendum_questions referendum_questions_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.referendum_questions
    ADD CONSTRAINT referendum_questions_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: referendum_questions referendum_questions_petition_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.referendum_questions
    ADD CONSTRAINT referendum_questions_petition_id_foreign FOREIGN KEY (petition_id) REFERENCES public.petitions(id) ON DELETE RESTRICT;


--
-- Name: referendum_questions referendum_questions_resulting_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.referendum_questions
    ADD CONSTRAINT referendum_questions_resulting_law_id_foreign FOREIGN KEY (resulting_law_id) REFERENCES public.laws(id) ON DELETE RESTRICT;


--
-- Name: remedy_recommendations remedy_recommendations_challenge_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.remedy_recommendations
    ADD CONSTRAINT remedy_recommendations_challenge_id_foreign FOREIGN KEY (challenge_id) REFERENCES public.constitutional_challenges(id) ON DELETE CASCADE;


--
-- Name: remedy_recommendations remedy_recommendations_finding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.remedy_recommendations
    ADD CONSTRAINT remedy_recommendations_finding_id_foreign FOREIGN KEY (finding_id) REFERENCES public.constitutional_findings(id) ON DELETE CASCADE;


--
-- Name: remedy_recommendations remedy_recommendations_judiciary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.remedy_recommendations
    ADD CONSTRAINT remedy_recommendations_judiciary_id_foreign FOREIGN KEY (judiciary_id) REFERENCES public.judiciaries(id) ON DELETE RESTRICT;


--
-- Name: removal_proceedings removal_proceedings_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.removal_proceedings
    ADD CONSTRAINT removal_proceedings_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE RESTRICT;


--
-- Name: removal_proceedings removal_proceedings_presided_by_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.removal_proceedings
    ADD CONSTRAINT removal_proceedings_presided_by_member_id_foreign FOREIGN KEY (presided_by_member_id) REFERENCES public.legislature_members(id) ON DELETE RESTRICT;


--
-- Name: removal_proceedings removal_proceedings_source_investigation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.removal_proceedings
    ADD CONSTRAINT removal_proceedings_source_investigation_id_foreign FOREIGN KEY (source_investigation_id) REFERENCES public.misconduct_investigations(id) ON DELETE SET NULL;


--
-- Name: residency_claims residency_claims_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.residency_claims
    ADD CONSTRAINT residency_claims_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: residency_claims residency_claims_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.residency_claims
    ADD CONSTRAINT residency_claims_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: residency_confirmations residency_confirmations_claim_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.residency_confirmations
    ADD CONSTRAINT residency_confirmations_claim_id_foreign FOREIGN KEY (claim_id) REFERENCES public.residency_claims(id) ON DELETE SET NULL;


--
-- Name: residency_confirmations residency_confirmations_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.residency_confirmations
    ADD CONSTRAINT residency_confirmations_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE CASCADE;


--
-- Name: residency_confirmations residency_confirmations_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.residency_confirmations
    ADD CONSTRAINT residency_confirmations_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: restoration_events restoration_events_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restoration_events
    ADD CONSTRAINT restoration_events_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: sentencing_orders sentencing_orders_case_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sentencing_orders
    ADD CONSTRAINT sentencing_orders_case_id_foreign FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE CASCADE;


--
-- Name: sentencing_orders sentencing_orders_issued_by_seat_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sentencing_orders
    ADD CONSTRAINT sentencing_orders_issued_by_seat_id_foreign FOREIGN KEY (issued_by_seat_id) REFERENCES public.judicial_seats(id) ON DELETE RESTRICT;


--
-- Name: sentencing_orders sentencing_orders_verdict_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sentencing_orders
    ADD CONSTRAINT sentencing_orders_verdict_id_foreign FOREIGN KEY (verdict_id) REFERENCES public.verdicts(id) ON DELETE RESTRICT;


--
-- Name: session_attendance session_attendance_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_attendance
    ADD CONSTRAINT session_attendance_member_id_foreign FOREIGN KEY (member_id) REFERENCES public.legislature_members(id) ON DELETE RESTRICT;


--
-- Name: session_attendance session_attendance_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_attendance
    ADD CONSTRAINT session_attendance_session_id_foreign FOREIGN KEY (session_id) REFERENCES public.legislature_sessions(id) ON DELETE CASCADE;


--
-- Name: setting_changes setting_changes_jurisdiction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.setting_changes
    ADD CONSTRAINT setting_changes_jurisdiction_id_foreign FOREIGN KEY (jurisdiction_id) REFERENCES public.jurisdictions(id) ON DELETE RESTRICT;


--
-- Name: setting_changes setting_changes_law_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.setting_changes
    ADD CONSTRAINT setting_changes_law_id_foreign FOREIGN KEY (law_id) REFERENCES public.laws(id) ON DELETE RESTRICT;


--
-- Name: setting_changes setting_changes_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.setting_changes
    ADD CONSTRAINT setting_changes_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE SET NULL;


--
-- Name: social_memberships social_memberships_space_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.social_memberships
    ADD CONSTRAINT social_memberships_space_id_foreign FOREIGN KEY (space_id) REFERENCES public.social_spaces(id) ON DELETE CASCADE;


--
-- Name: social_posts social_posts_thread_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.social_posts
    ADD CONSTRAINT social_posts_thread_id_foreign FOREIGN KEY (thread_id) REFERENCES public.social_threads(id) ON DELETE CASCADE;


--
-- Name: social_reactions social_reactions_post_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.social_reactions
    ADD CONSTRAINT social_reactions_post_id_foreign FOREIGN KEY (post_id) REFERENCES public.social_posts(id) ON DELETE CASCADE;


--
-- Name: social_spaces social_spaces_owner_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.social_spaces
    ADD CONSTRAINT social_spaces_owner_user_id_foreign FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: social_subforums social_subforums_space_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.social_subforums
    ADD CONSTRAINT social_subforums_space_id_foreign FOREIGN KEY (space_id) REFERENCES public.social_spaces(id) ON DELETE CASCADE;


--
-- Name: social_threads social_threads_subforum_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.social_threads
    ADD CONSTRAINT social_threads_subforum_id_foreign FOREIGN KEY (subforum_id) REFERENCES public.social_subforums(id) ON DELETE CASCADE;


--
-- Name: support_reports support_reports_reporter_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.support_reports
    ADD CONSTRAINT support_reports_reporter_id_foreign FOREIGN KEY (reporter_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: sync_cursors sync_cursors_peer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sync_cursors
    ADD CONSTRAINT sync_cursors_peer_id_foreign FOREIGN KEY (peer_id) REFERENCES public.federation_peers(id) ON DELETE CASCADE;


--
-- Name: tabulation_rounds tabulation_rounds_candidacy_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tabulation_rounds
    ADD CONSTRAINT tabulation_rounds_candidacy_id_foreign FOREIGN KEY (candidacy_id) REFERENCES public.candidacies(id) ON DELETE RESTRICT;


--
-- Name: tabulation_rounds tabulation_rounds_tabulation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tabulation_rounds
    ADD CONSTRAINT tabulation_rounds_tabulation_id_foreign FOREIGN KEY (tabulation_id) REFERENCES public.tabulations(id) ON DELETE CASCADE;


--
-- Name: tabulations tabulations_excluded_candidacy_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tabulations
    ADD CONSTRAINT tabulations_excluded_candidacy_id_foreign FOREIGN KEY (excluded_candidacy_id) REFERENCES public.candidacies(id) ON DELETE RESTRICT;


--
-- Name: tabulations tabulations_race_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tabulations
    ADD CONSTRAINT tabulations_race_id_foreign FOREIGN KEY (race_id) REFERENCES public.election_races(id) ON DELETE CASCADE;


--
-- Name: terms terms_holder_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.terms
    ADD CONSTRAINT terms_holder_user_id_foreign FOREIGN KEY (holder_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: terms terms_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.terms
    ADD CONSTRAINT terms_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE SET NULL;


--
-- Name: terms terms_source_appointment_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.terms
    ADD CONSTRAINT terms_source_appointment_id_foreign FOREIGN KEY (source_appointment_id) REFERENCES public.appointments(id) ON DELETE SET NULL;


--
-- Name: terms terms_source_election_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.terms
    ADD CONSTRAINT terms_source_election_id_foreign FOREIGN KEY (source_election_id) REFERENCES public.elections(id) ON DELETE SET NULL;


--
-- Name: users users_invited_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_invited_by_user_id_foreign FOREIGN KEY (invited_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: vacancies vacancies_countback_tabulation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vacancies
    ADD CONSTRAINT vacancies_countback_tabulation_id_foreign FOREIGN KEY (countback_tabulation_id) REFERENCES public.tabulations(id) ON DELETE SET NULL;


--
-- Name: vacancies vacancies_declared_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vacancies
    ADD CONSTRAINT vacancies_declared_by_foreign FOREIGN KEY (declared_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: vacancies vacancies_legislature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vacancies
    ADD CONSTRAINT vacancies_legislature_id_foreign FOREIGN KEY (legislature_id) REFERENCES public.legislatures(id) ON DELETE CASCADE;


--
-- Name: vacancies vacancies_special_election_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vacancies
    ADD CONSTRAINT vacancies_special_election_id_foreign FOREIGN KEY (special_election_id) REFERENCES public.elections(id) ON DELETE SET NULL;


--
-- Name: verdicts verdicts_case_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.verdicts
    ADD CONSTRAINT verdicts_case_id_foreign FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE CASCADE;


--
-- Name: vote_casts vote_casts_board_seat_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vote_casts
    ADD CONSTRAINT vote_casts_board_seat_id_foreign FOREIGN KEY (board_seat_id) REFERENCES public.board_seats(id) ON DELETE RESTRICT;


--
-- Name: vote_casts vote_casts_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vote_casts
    ADD CONSTRAINT vote_casts_member_id_foreign FOREIGN KEY (member_id) REFERENCES public.legislature_members(id) ON DELETE RESTRICT;


--
-- Name: vote_casts vote_casts_vote_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vote_casts
    ADD CONSTRAINT vote_casts_vote_id_foreign FOREIGN KEY (vote_id) REFERENCES public.chamber_votes(id) ON DELETE CASCADE;


--
-- Name: warrants warrants_case_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warrants
    ADD CONSTRAINT warrants_case_id_foreign FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE CASCADE;


--
-- Name: warrants warrants_issued_by_seat_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warrants
    ADD CONSTRAINT warrants_issued_by_seat_id_foreign FOREIGN KEY (issued_by_seat_id) REFERENCES public.judicial_seats(id) ON DELETE RESTRICT;


--
-- Name: warrants warrants_subject_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warrants
    ADD CONSTRAINT warrants_subject_user_id_foreign FOREIGN KEY (subject_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- PostgreSQL database dump complete
--

