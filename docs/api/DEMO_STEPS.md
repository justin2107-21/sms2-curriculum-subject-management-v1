# Demo guide: Reported offerings (Windows, XAMPP, PowerShell)

This shows the "Reported offerings" feature using a **test copy of the database** (`sms_db_uitest`). Your real database `sms_db` is never touched. Everything below is pasted into **Windows PowerShell**.

You will use **two PowerShell windows**:
- **Window A** runs the demo web server. It stays open (it looks "stuck"; that is normal).
- **Window B** is where you create the client and send data.

Never paste your API key or the pepper into chat, email, a commit or a document.

---

## Part 1. Use the test database for the demo (and switch back)

Switching the XAMPP/Apache app to the test database means editing Apache settings, which is risky, so **this guide does not do that**. Instead it starts a **second, temporary web server** on port 8090 that reads `sms_db_uitest`. Your normal site (`http://localhost/sms-csms`, database `sms_db`) is not changed and keeps working.

**Step 1. Make sure MySQL is running.** Open the XAMPP Control Panel and check that MySQL shows "Running" (Apache is not needed for this demo).
Expected: MySQL has a green "Running" label.

**Step 2. Check that the test database has the new tables.** In Window B:
```powershell
Set-Location C:\xampp\htdocs\sms-csms
& 'C:\xampp\mysql\bin\mysql.exe' -u root sms_db_uitest -e "SHOW TABLES LIKE 'reported_offerings'"
```
Expected: a header line `Tables_in_sms_db_uitest (reported_offerings)` and one line `reported_offerings`. If you see nothing, the test database is missing migration 029; stop and ask for help.

**Step 3. Start the demo server (Window A).** Open a new PowerShell window and paste (this is where the pepper from Part 2 is also needed, so do Step 4 first, then come back and run this in Window A):
```powershell
Set-Location C:\xampp\htdocs\sms-csms
$env:DB_NAME = 'sms_db_uitest'
$env:API_KEY_PEPPER = (Get-Content "$env:USERPROFILE\csms-demo-pepper.txt").Trim()
C:\xampp\php\php.exe -S 127.0.0.1:8090 -t C:\xampp\htdocs\sms-csms
```
Expected: `Development Server (http://127.0.0.1:8090) started`. Leave this window open. To stop the demo server later press `Ctrl+C` in this window.

**How to switch back:** nothing to do. Close Window A (or press `Ctrl+C`). The settings above exist only inside that window; your normal site still uses `sms_db`.

---

## Part 2. The pepper (a secret used to protect API keys)

**Step 4. Create the pepper and save it outside the project.** In Window B:
```powershell
$b = New-Object byte[] 32
[Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
($b | ForEach-Object { $_.ToString('x2') }) -join '' | Set-Content "$env:USERPROFILE\csms-demo-pepper.txt" -Encoding ascii
(Get-Content "$env:USERPROFILE\csms-demo-pepper.txt").Trim().Length
```
Expected: `64` (the length only; the value is not shown).

Keep this file safe: it lives in your user folder, **outside the project**, so Git never sees it. Do not copy it into the project. If you lose it or change it, every key made with it stops working (you would create a new client). Use a separate pepper for any real (non-demo) setup.

**Step 5. Load the pepper into Window B** (do this in every new Window B):
```powershell
$env:DB_NAME = 'sms_db_uitest'
$env:API_KEY_PEPPER = (Get-Content "$env:USERPROFILE\csms-demo-pepper.txt").Trim()
$env:API_KEY_PEPPER.Length
```
Expected: `64`.

Now go back and run **Step 3** in Window A.

---

## Part 3. Create the demo API client and keep the key

**Step 6. Create the client** (Window B). This asks the database to remember a "Demo Class Scheduling" sender and prints its key **once**. The command saves the key into a variable so you never type it:
```powershell
Set-Location C:\xampp\htdocs\sms-csms
$out = & C:\xampp\php\php.exe scripts\api-client-create.php create --name="Demo Class Scheduling" --source=demo-class-scheduling --scope=read_write --operator=uitest.superadmin
$out | Select-String '^API client'
$env:CSMS_API_KEY = ($out | Select-String '^csms_\S+$').Line.Trim()
"Key saved. Length: $($env:CSMS_API_KEY.Length)"
```
Expected:
```
API client: Demo Class Scheduling (source demo-class-scheduling, scope read_write)
Key saved. Length: 57
```
The key is now only in this window's memory (`$env:CSMS_API_KEY`). Closing the window forgets it. It cannot be shown again. If you lose it before finishing, see "If something fails", problem 3.

**Step 7. Check the client exists:**
```powershell
& C:\xampp\php\php.exe scripts\api-client-create.php list
```
Expected: one line starting `Demo Class Scheduling	demo-class-scheduling	read_write`, then an 8-character prefix, `active`, `never`.

---

## Part 4. Send the demo data

The demo file is `docs\api\offering-demo.http`. Its 7 items use real subject codes from the test database. Use **either** Option 1 (curl) **or** Option 2 (VS Code). The address used here is `http://127.0.0.1:8090/api/v1/offerings.php`. (The shorter address without `.php` only works on Apache, not on this demo server.)

### Option 1: curl.exe

**Step 8. Pull the JSON batch out of the demo file** (Window B):
```powershell
$http = Get-Content docs\api\offering-demo.http -Raw
$m = [regex]::Match($http, '(?ms)^\{"offerings".*?^\]\}')
Set-Content "$env:TEMP\demo-batch.json" $m.Value -Encoding ascii
"Batch found: $($m.Success)"
```
Expected: `Batch found: True`.

**Step 9. Send it:**
```powershell
curl.exe -sS -X POST http://127.0.0.1:8090/api/v1/offerings.php -H "Authorization: Bearer $env:CSMS_API_KEY" -H "Content-Type: application/json" --data-binary "@$env:TEMP\demo-batch.json"
```
Expected (one long line; the important part is the start and the item list):
```
{"summary":{"received":7,"accepted":5,"flagged":1,"rejected":1,"created":6,"updated":0,"unchanged":0},"results":[
 {"index":0,"external_id":"DEMO-0001","status":"accepted","action":"created","flags":[]},
 ... items 1 to 4 accepted and created ...
 {"index":5,"external_id":"DEMO-0006","status":"flagged","action":"created","flags":["subject_inactive"]},
 {"index":6,"external_id":"DEMO-0007","status":"rejected","error":{"code":"unknown_subject","field":"subject_code"}}],"request_id":"..."}
```
That is: **7 received, 5 accepted, 1 flagged (CC101 is an inactive subject), 1 rejected (NOPE999 does not exist, so it is refused and nothing is created).**

**Step 10. Send the same file again to prove a replay changes nothing:**
```powershell
curl.exe -sS -X POST http://127.0.0.1:8090/api/v1/offerings.php -H "Authorization: Bearer $env:CSMS_API_KEY" -H "Content-Type: application/json" --data-binary "@$env:TEMP\demo-batch.json"
```
Expected: `"created":0,"updated":0,"unchanged":6` in the summary (the rejected item is rejected again), and every valid item shows `"action":"unchanged"`. No duplicates are made.

**Step 11 (optional). Read back what was stored:**
```powershell
curl.exe -sS "http://127.0.0.1:8090/api/v1/offerings.php?program_code=BSIT&per_page=2" -H "Authorization: Bearer $env:CSMS_API_KEY"
```
Expected: `{"data":[ ... two records ... ],"page":1,"per_page":2,"total":6,...}`.

### Option 2: VS Code REST Client

You need the "REST Client" extension (by Huachao Mao) installed in VS Code.

**Step 12. Make a private copy of the demo file that points at the demo server** (Window B). The copy contains your key, so it is placed in your temp folder, not in the project:
```powershell
$text = (Get-Content docs\api\offering-demo.http -Raw).Replace('http://localhost/sms-csms','http://127.0.0.1:8090').Replace('/api/v1/offerings','/api/v1/offerings.php').Replace('{{$processEnv CSMS_API_KEY}}',$env:CSMS_API_KEY)
Set-Content "$env:TEMP\demo-local.http" $text -Encoding utf8
code "$env:TEMP\demo-local.http"
```
Expected: the file opens in VS Code. The first lines show `@host = http://127.0.0.1:8090` and the requests use `/api/v1/offerings.php`.

**Step 13. Send request 1.** Click the small grey **Send Request** link above the line `POST {{host}}/api/v1/offerings.php`. A panel opens on the right.
Expected: `HTTP/1.1 200 OK` and the same body as Step 9 (7 received, 5 accepted, 1 flagged, 1 rejected). Click **Send Request** a second time to prove the replay: `"unchanged":6`.

**Step 14. Delete the private copy when you are done:**
```powershell
Remove-Item "$env:TEMP\demo-local.http"
```
Expected: no output.

---

## Part 5. See it on the page

**Step 15. Get the test login password onto your clipboard** (it is stored in the git-ignored `.env.uitest`; this copies it without printing it):
```powershell
((Get-Content C:\xampp\htdocs\sms-csms\.env.uitest | Where-Object { $_ -like 'UITEST_SUPERADMIN_PASS=*' }) -split '=', 2)[1] | Set-Clipboard
```
Expected: no output. Paste the password in the login form in the next step.

**Step 16. Open the demo site and log in.** In your browser go to `http://127.0.0.1:8090/auth/signin.php`. Username `uitest.superadmin`, password = paste from the clipboard.
Expected: you reach the dashboard.

**Step 17. Open Subject Offering History.** In the left menu open the history page, or go to `http://127.0.0.1:8090/modules/curriculum/pages/subject_history_view.php`.
Expected: at the top right a grey chip reading **"Read-only: placement history plus reported offerings."**

**Step 18. Pick the context.** In the bar at the top choose:
- Program / Strand: **BSIT**
- Curriculum: **BSIT-DEMO-2026**
- Curriculum Version: **PREORAL-2026**

Expected: a table of about 50 subjects with a last column named **Reported**. (If you pick the curricula "123" or other versions you will see few subjects and no reports.)

**Step 19. Find the demo subjects.** Type each code in the search box above the table. You should see this in the **Reported** column:

| Search for | Reported shows |
|---|---|
| CC104 | 2 |
| CC105 | 1 |
| CC106 | 1 |
| CC101 | 1 |
| DM102 | 1 |
| CCS1102 | `-` (no reports) |

**Step 20. Open CC104 (click its row).** In the dialog, scroll below the version list. Expected: a heading **Reported offerings** with an info icon, and a table with two rows, newest first:
- 2026-2027, 1st Semester, **Offered**, sections 4, enrolled 132
- 2025-2026, 2nd Semester, **Offered**, sections 2, enrolled 61

Both rows show source system `demo-class-scheduling` followed by a small blue **Demo** chip, and a "Received" date (today).

**Step 21. Open CC101.** Expected: one row, 2026-2027, 1st Semester, Offered, sections 2, enrolled 55, and a yellow flag chip **Subject inactive** (CC101 is inactive in the Subject Master, so it was accepted with a flag).

**Step 22. Open CC106.** Expected: one row, 2026-2027, 2nd Semester, with a red **Cancelled** chip, sections 0, enrolled shown as a dash.

**Step 23. Open CCS1102/CC102 (no reports).** Expected: the Reported offerings heading and, instead of a table, the message **"No offering data received yet."**

What you will not see: `NOPE999` (rejected, nothing stored) and `MA102` (not sent in this demo).

---

## Part 6. Clean up and troubleshooting

**Step 24. Remove the demo rows from the test database.** This command names `sms_db_uitest` explicitly, so it cannot touch `sms_db`. It shows the database name and the counts so you can check:
```powershell
$sql = @"
SELECT DATABASE() AS target_database;
SELECT COUNT(*) AS demo_rows_before FROM reported_offerings WHERE source_system = 'demo-class-scheduling';
DELETE FROM reported_offerings WHERE source_system = 'demo-class-scheduling';
DELETE r FROM api_rejected_items r JOIN api_clients c ON c.id = r.api_client_id WHERE c.source_system = 'demo-class-scheduling';
SELECT COUNT(*) AS demo_rows_after FROM reported_offerings WHERE source_system = 'demo-class-scheduling';
"@
$sql | & 'C:\xampp\mysql\bin\mysql.exe' -u root sms_db_uitest
```
Expected: `sms_db_uitest`, then `6`, then `0`.

The client "Demo Class Scheduling" stays so you can demo again: load Step 5, run the two `rotate` lines below for a fresh key, then repeat Step 9.
```powershell
$out = & C:\xampp\php\php.exe scripts\api-client-create.php rotate --name="Demo Class Scheduling" --operator=uitest.superadmin
$env:CSMS_API_KEY = ($out | Select-String '^csms_\S+$').Line.Trim()
```

**Step 25 (optional). Remove the client completely.** Only after Step 24. *(Not run in my verification.)*
```powershell
& 'C:\xampp\mysql\bin\mysql.exe' -u root sms_db_uitest -e "DELETE FROM api_clients WHERE source_system = 'demo-class-scheduling'"
```
Do not use `revoke` for the demo: a revoked client cannot be rotated and its name cannot be reused. `revoke` is for real clients.

**Step 26. Close the demo.** In Window A press `Ctrl+C`; close both windows. Delete `$env:TEMP\demo-batch.json` if you made it: `Remove-Item "$env:TEMP\demo-batch.json"`.

### If something fails (most likely first)

1. **`API_KEY_PEPPER must be set in the environment (at least 32 characters).`** The pepper is not loaded in this window. Run Step 5 again, then repeat the failed command. (If the *server* answers 401 to a correct key, Window A was started without the same pepper: stop it with `Ctrl+C`, and re-run Step 3.)
2. **`404` or an empty answer from curl, or the page address does not open.** Check you used `/api/v1/offerings.php` (with `.php`) and port **8090**, and that Window A still shows the server running. If Window A says the port is in use, close the old server window or use another port in both Step 3 and the commands. `curl: (7) Failed to connect` means the server in Window A is not running.
3. **`401 unauthorized`, or Step 6 fails with a long red "Duplicate entry 'Demo Class Scheduling'" message.** The key variable is empty or old, or the client already exists. If the client already exists (the red message), you do not need to create it again: run the `rotate` commands under Step 24 to get a new key into `$env:CSMS_API_KEY`. If you opened a new window, redo Step 5 first.

Also seen: `--operator must be an active user.` means the operator name is mistyped; use `uitest.superadmin`.

---

### What I could and could not verify

Run for real against a throwaway copy of `sms_db_uitest` (since deleted): the pepper creation, client creation, key capture, `list`, the demo server on a spare port, the batch extraction, both curl calls (7/5/1/1, replay 6 unchanged), the read-back, wrong key (401), `.php` address works and the short address is 404 on the demo server, the cleanup SQL (6 to 0), `rotate`, `revoke` (and that a revoked client cannot be rotated), the three failure messages, and the REST Client copy command (its address lines). The numbers in Part 5 come from the page's own data endpoints for those subjects, read after logging in as `uitest.superadmin`.

**Not verified:** anything visual in a browser (chip colours, layout, the clipboard step, the login page), the VS Code "Send Request" click, `code` opening the file, and Step 25. The login password step relies on `.env.uitest` having `UITEST_SUPERADMIN_PASS`, which it does on this computer.
