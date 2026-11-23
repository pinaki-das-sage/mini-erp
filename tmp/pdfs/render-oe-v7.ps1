Add-Type -AssemblyName System.Runtime.WindowsRuntime
$null = [Windows.Storage.StorageFile, Windows.Storage, ContentType=WindowsRuntime]
$null = [Windows.Data.Pdf.PdfDocument, Windows.Data.Pdf, ContentType=WindowsRuntime]
$null = [Windows.Storage.Streams.InMemoryRandomAccessStream, Windows.Storage.Streams, ContentType=WindowsRuntime]
$taskMethod = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object { $_.Name -eq 'AsTask' -and $_.IsGenericMethodDefinition -and $_.GetParameters().Count -eq 1 -and $_.GetGenericArguments().Count -eq 1 } | Select-Object -First 1
function Wait-Result($operation, [Type]$resultType) {
    $pending = $taskMethod.MakeGenericMethod($resultType).Invoke($null, @($operation))
    $pending.Wait()
    $pending.Result
}
$actionMethod = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object { $_.Name -eq 'AsTask' -and -not $_.IsGenericMethod -and $_.GetParameters().Count -eq 1 } | Select-Object -First 1
$sourcePath = 'C:\Users\sakhadka\Downloads\order_entry_oe_api_priorities_and_stories_v7_1.pdf'
$file = Wait-Result ([Windows.Storage.StorageFile]::GetFileFromPathAsync($sourcePath)) ([Windows.Storage.StorageFile])
$pdf = Wait-Result ([Windows.Data.Pdf.PdfDocument]::LoadFromFileAsync($file)) ([Windows.Data.Pdf.PdfDocument])
foreach ($pageIndex in @(9, 13, 15)) {
    $page = $pdf.GetPage($pageIndex)
    $memory = New-Object Windows.Storage.Streams.InMemoryRandomAccessStream
    $pending = $actionMethod.Invoke($null, @($page.RenderToStreamAsync($memory)))
    $pending.Wait()
    $inputStream = [System.IO.WindowsRuntimeStreamExtensions]::AsStreamForRead($memory)
    $target = Join-Path (Get-Location) ('tmp\pdfs\oe-v7-page-' + ($pageIndex + 1) + '.png')
    $outputStream = [System.IO.File]::Create($target)
    $inputStream.CopyTo($outputStream)
    $outputStream.Dispose()
    $inputStream.Dispose()
    $memory.Dispose()
    $page.Dispose()
    Write-Output $target
}
