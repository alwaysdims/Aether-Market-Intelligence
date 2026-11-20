param(
    [string]$Path = 'E:\Aether-Market-Intelligence\scripts\repair_recovery_schema_and_grant_operational_writer.sql'
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $Path)) {
    throw "Missing script: $Path"
}

$sql = Get-Content -Raw -LiteralPath $Path

$required = @(
    'project_bot_recovery_20261110',
    'aether_ingestion',
    'this grant-only script never creates replacement tables',
    'GRANT SELECT, INSERT, UPDATE ON TABLE public.news_items TO aether_ingestion',
    'GRANT SELECT, INSERT, UPDATE ON TABLE public.broker_summaries TO aether_ingestion',
    'GRANT SELECT, INSERT, UPDATE ON TABLE public.analysis_runs TO aether_ingestion',
    'GRANT SELECT, INSERT, UPDATE ON TABLE public.market_analyses TO aether_ingestion',
    'GRANT SELECT, INSERT, UPDATE ON TABLE public.universe_discovery_runs TO aether_ingestion',
    'GRANT SELECT, INSERT, UPDATE ON TABLE public.universe_discovery_items TO aether_ingestion',
    'GRANT SELECT, INSERT, UPDATE ON TABLE public.idx_company_registries TO aether_ingestion',
    'GRANT SELECT, INSERT, UPDATE ON TABLE public.evidence_snapshots TO aether_ingestion',
    'GRANT SELECT, INSERT, UPDATE ON TABLE public.scheduled_telegram_jobs TO aether_ingestion',
    'GRANT SELECT, INSERT, UPDATE ON TABLE public.telegram_conversations TO aether_ingestion',
    'GRANT SELECT, INSERT, UPDATE ON TABLE public.telegram_outbox TO aether_ingestion',
    'GRANT SELECT, INSERT, UPDATE ON TABLE public.notification_logs TO aether_ingestion',
    'GRANT SELECT ON TABLE public.market_observations TO aether_ingestion',
    'GRANT SELECT ON TABLE public.company_analysis_metrics TO aether_ingestion',
    'GRANT SELECT ON TABLE public.company_corporate_actions TO aether_ingestion',
    'GRANT SELECT ON TABLE public.prediction_signals TO aether_ingestion',
    'GRANT SELECT, INSERT ON TABLE public.data_revision_archives TO aether_ingestion',
    'GRANT USAGE, SELECT ON SEQUENCE public.scheduled_telegram_jobs_id_seq TO aether_ingestion',
    'GRANT USAGE, SELECT ON SEQUENCE public.telegram_conversations_id_seq TO aether_ingestion',
    'GRANT USAGE, SELECT ON SEQUENCE public.telegram_outbox_id_seq TO aether_ingestion',
    'GRANT USAGE, SELECT ON SEQUENCE public.notification_logs_id_seq TO aether_ingestion',
    'GRANT USAGE, SELECT ON SEQUENCE public.universe_discovery_runs_id_seq TO aether_ingestion',
    'GRANT USAGE, SELECT ON SEQUENCE public.universe_discovery_items_id_seq TO aether_ingestion',
    'GRANT USAGE, SELECT ON SEQUENCE public.evidence_snapshots_id_seq TO aether_ingestion',
    'GRANT USAGE, SELECT ON SEQUENCE public.data_revision_archives_id_seq TO aether_ingestion',
    'GRANT SELECT ON TABLE public.prompt_templates TO aether_ingestion',
    'REVOKE DELETE'
)

foreach ($needle in $required) {
    if (-not $sql.Contains($needle)) {
        throw "Missing required guard or grant: $needle"
    }
}

$forbidden = @(
    'GRANT ALL PRIVILEGES',
    'GRANT ALL ON ALL TABLES',
    'GRANT ALL ON ALL SEQUENCES',
    'ALTER ROLE aether_ingestion SUPERUSER',
    'CREATEDB',
    'CREATEROLE',
    'BYPASSRLS',
    'GRANT CREATE ON SCHEMA public'
)

foreach ($needle in $forbidden) {
    if ($sql.Contains($needle)) {
        throw "Forbidden privilege expansion: $needle"
    }
}

Write-Output 'grant_contract=PASS'
