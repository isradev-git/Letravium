<?php

namespace App\Http\Controllers;

class SyncContractController extends Controller
{
    public function show()
    {
        return response()->json([
            'contract_version' => 1,
            'local_data' => [
                'protected_local_copy' => true,
                'local_unlock_password_separate_from_account' => true,
                'server_totp_secret_distributed_to_device' => false,
                'local_unlock_creates_server_session' => false,
            ],
            'lock' => [
                'on_app_close' => true,
                'on_system_lock' => true,
                'idle_timeout_minutes' => 15,
                'timeout_configurable_without_disabling' => true,
            ],
            'recovery' => [
                'online_redownload_after_forgotten_local_password' => true,
                'local_changes_with_lost_key_may_be_irrecoverable' => true,
                'risk_explained_during_preparation' => true,
                'remote_wipe_of_downloaded_copy_guaranteed' => false,
            ],
            'projects' => [
                'only_selected_authorized_manifests' => true,
                'authorization_requires_online_password_and_totp' => true,
                'manifest_read_endpoint' => '/sync/devices/{device}',
                'revocation_rejects_future_sync_and_reads' => true,
            ],
            'exports' => [
                'offline_pdf_txt_local_backup_when_resources_present' => true,
                'docx_epub_server_queued_until_online' => true,
                'queued_request_keeps_pinned_version_selection' => true,
                'offline_server_export_announced_available' => false,
            ],
            'conflicts' => [
                'preserve_both_sides' => true,
                'deleted_local_edit_kept_pending' => true,
                'automatic_resurrection' => false,
                'order_conflicts_are_structural' => true,
                'missing_resources_reported_without_dangling_references' => true,
                'never_resolve_by_device_clock' => true,
            ],
        ]);
    }
}
