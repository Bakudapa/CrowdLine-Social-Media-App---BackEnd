-- Runs once when a Postgres data volume is first initialized.
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;

-- Separate database for the test suite so RefreshDatabase never wipes dev/benchmark data.
CREATE DATABASE crowdline_test;
