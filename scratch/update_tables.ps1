$files = @(
    "F:\PROJECT\SalonMS\templates\customers\row.twig",
    "F:\PROJECT\SalonMS\templates\employees\row.twig",
    "F:\PROJECT\SalonMS\templates\inventory\row.twig",
    "F:\PROJECT\SalonMS\templates\services\row.twig",
    "F:\PROJECT\SalonMS\templates\appointments\list_item.twig",
    "F:\PROJECT\SalonMS\templates\inventory\index.twig",
    "F:\PROJECT\SalonMS\templates\services\index.twig",
    "F:\PROJECT\SalonMS\templates\customers\index.twig",
    "F:\PROJECT\SalonMS\templates\employees\index.twig"
)

foreach ($file in $files) {
    if (Test-Path $file) {
        $content = Get-Content $file -Raw
        
        # Increase cell padding
        $content = $content -replace 'px-6 py-4', 'px-6 py-5'
        
        # Increase button padding for table actions
        $content = $content -replace 'p-1"', 'p-2"'
        $content = $content -replace "p-1'", "p-2'"
        
        # Increase icon sizes in table actions
        $content = $content -replace 'w-4 h-4"', 'w-5 h-5"'
        
        Set-Content $file -Value $content
        Write-Host "Updated $file"
    }
}
