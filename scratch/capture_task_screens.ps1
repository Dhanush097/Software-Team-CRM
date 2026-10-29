$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
$artDir = 'C:\Users\vanga\.gemini\antigravity\brain\e1ec6121-03cd-4c78-81a5-92700267bcf1'

function Capture-Screen($theme, $size, $filename, $extraParams) {
    $out = Join-Path $artDir $filename
    if (Test-Path $out) { Remove-Item $out -Force }
    $url = "http://127.0.0.1:8000/scratch/auth_session.php?theme=$theme&$extraParams"
    $args = @(
        '--headless=new',
        '--disable-gpu',
        '--no-sandbox',
        "--window-size=$size",
        '--virtual-time-budget=5000',
        "--screenshot=$out",
        $url
    )
    if ($theme -eq 'dark') {
        $args = @('--force-dark-mode') + $args
    }
    $p = Start-Process -FilePath $chrome -ArgumentList $args -Wait -PassThru
    Write-Host "Captured $filename (ExitCode: $($p.ExitCode))"
}

# 1. Add Task screen with empty state: [ Select team members... ] [ + Add Members ]
Capture-Screen 'light' '1440,960' 'task_add_empty_state.png' 'task_add=1'

# 2. Add Task screen with Assignees Selection Modal open
Capture-Screen 'light' '1440,960' 'task_add_assignees_modal.png' 'task_add=1&assignees_modal=1'

# 3. Add Task screen with Member Chips: [ DH — Dhanush x ] [ SK — Suresh x ] ... [ + Add Members ]
Capture-Screen 'light' '1440,960' 'task_add_with_chips.png' 'task_add=1&with_chips=1'

# 4. Add Task screen in Dark Mode with chips
Capture-Screen 'dark' '1440,960' 'task_add_dark_with_chips.png' 'task_add=1&with_chips=1'

# 5. Task Detail screen showing multiple assigned members
Capture-Screen 'light' '1440,960' 'task_detail_with_assignees.png' 'task_detail=1'

# 6. Task Edit screen showing preloaded assignees and chips
Capture-Screen 'light' '1440,960' 'task_edit_with_chips.png' 'task_edit=1'

Get-ChildItem $artDir -Filter '*task*.png' | Sort-Object LastWriteTime -Descending | Select-Object -First 10 Name, Length, LastWriteTime
