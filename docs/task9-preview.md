# Task 9 disposable interactive preview

This preview is an isolated synthetic installation. It does not contain BCP academic data and does not claim institutional adoption or approval.

Open `http://127.0.0.1:49792/auth/signin.php`. Read the generated local credentials from:

```powershell
Get-Content -LiteralPath 'C:\Users\esquitajustin\AppData\Local\CSMS-SMS2\task9-preview\login.txt'
```

The editor role contains only a test-specific explicit permission allowlist. The viewer account has read access to the test workspace. Existing role grants were not changed. Test programs and subjects are visibly marked synthetic; the reference source is a fixture locator with evidence unassessed.

## Verify preview identity

From `C:\xampp\htdocs\sms-csms-task9`:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\task9-preview.ps1 -Action Status
```

The command verifies the MariaDB version, loopback port, canonical disposable data directory, selected disposable database, and `foreign_key_checks=1` before reporting the preview. It never falls back to the ordinary XAMPP port 3306. The current preview uses MariaDB 10.4.32 on loopback port 49791 and HTTP port 49792.

An authenticated route check is available with:

```powershell
& 'C:\xampp\php\php.exe' .\tools\task9-preview-check.php 'C:\Users\esquitajustin\AppData\Local\CSMS-SMS2\task9-preview'
```

It checks the actual Program summary and version, Mapping, graduate outcome, CLO/assessment, dashboard analytics, and existing authenticated curriculum workflow routes.

## Manual interaction check

1. Sign in as `task9_preview` and open Dashboard. Select the synthetic program, curriculum, and proposed revision; confirm metrics load for that revision and links retain the selected context.
2. Open Curriculum Builder → Academic Programs. Open Program Details; confirm counts and the note that archived records are included. Open the curriculum, then select a version. Confirm a real version list and placement table load.
3. Open Subject Mapping from the revision context. Confirm the selector chain is enabled and shows the chosen revision. Change the program and confirm old curriculum/version values clear. Inspect IT01–IT13 and the synthetic mapping, then the CLO/assessment workspace.
4. Sign out and sign in as `task9_viewer`. Confirm the read-only workspace loads and attempted write controls/actions remain unavailable or are denied.
5. If manually testing a failure state, use only a disposable test copy. Do not alter the preview’s schema unless you intend to reset it with the explicit reset confirmation.

## Start, stop, and reset

The preview stays running for user testing. Starting again resumes the same synthetic database. Stop preserves its data:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\task9-preview.ps1 -Action Stop
```

Start it again with `-Action Start`. Reset deletes only the guarded Task 9 preview directory and requires the exact marker printed by `-Action Status`:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\task9-preview.ps1 -Action Reset -ConfirmReset <exact-marker>
```

The preview script refuses a mismatched worktree root, manifest, schema, data directory, or process identity. The app code and tests do not connect to normal XAMPP or production databases as part of this preview.

Automated HTTP and structural frontend checks passed. No in-app browser was available during this run, so visual appearance and the manual interaction checklist still need a human browser check.