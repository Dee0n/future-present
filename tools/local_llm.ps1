# Start LM Studio's server for WSL and load the local model the way CHIM expects it:
# text only, 14k context, fully on the GPU. Run on Windows: powershell -File tools\local_llm.ps1
$lms = "$env:USERPROFILE\.lmstudio\bin\lms.exe"
& $lms server start --bind 0.0.0.0 -p 1234
& $lms unload --all
& $lms load qwen/qwen3.5-4b -c 14336 --gpu max --parallel 2 -y
& $lms ps
