$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
$out = 'C:\Users\vanga\.gemini\antigravity\brain\e1ec6121-03cd-4c78-81a5-92700267bcf1\crm_redesign_form_modal.png'
if (Test-Path $out) { Remove-Item $out -Force }
$args = @(
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--window-size=1440,920',
    '--virtual-time-budget=3500',
    "--screenshot=$out",
    'http://127.0.0.1:8000/scratch/auth_session.php?task_add=1&with_chips=1&theme=light'
)
$p = Start-Process -FilePath $chrome -ArgumentList $args -Wait -PassThru
Write-Host "Captured Form Modal: $(Test-Path $out)"
