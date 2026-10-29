$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
$out = 'C:\Users\vanga\.gemini\antigravity\brain\e1ec6121-03cd-4c78-81a5-92700267bcf1\settings_view_with_replay_intro.png'
if (Test-Path $out) { Remove-Item $out -Force }
$args = @(
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--window-size=1440,1450',
    "--screenshot=$out",
    'http://127.0.0.1:8000/scratch/auth_session.php?view=settings'
)
$p = Start-Process -FilePath $chrome -ArgumentList $args -Wait -PassThru
Write-Host "ExitCode: $($p.ExitCode)"
Write-Host "Exists: $(Test-Path $out)"
