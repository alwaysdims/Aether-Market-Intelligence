param([Parameter(Mandatory=$true)][string]$Path)
$secure = Get-Content -Raw -LiteralPath $Path | ConvertTo-SecureString
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try { [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
