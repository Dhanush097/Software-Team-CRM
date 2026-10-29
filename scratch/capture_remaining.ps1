$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
$artDir = 'C:\Users\vanga\.gemini\antigravity\brain\e1ec6121-03cd-4c78-81a5-92700267bcf1'

function Capture($theme, $size, $filename, $extraParams) {
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
    $p = Start-Process -FilePath $chrome -ArgumentList $args -Wait -PassThru
    Write-Host "Captured $filename with exit code $($p.ExitCode)"
}

Capture 'light' '1440,960' 'task_detail_with_assignees.png' 'task_detail=1'
Capture 'light' '1440,960' 'task_edit_with_chips.png' 'task_edit=1'
