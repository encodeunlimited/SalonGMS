$files = @(
    "F:\PROJECT\SalonMS\templates\customers\modal.twig",
    "F:\PROJECT\SalonMS\templates\employees\modal.twig",
    "F:\PROJECT\SalonMS\templates\inventory\modal.twig",
    "F:\PROJECT\SalonMS\templates\inventory\issue_modal.twig",
    "F:\PROJECT\SalonMS\templates\services\modal.twig",
    "F:\PROJECT\SalonMS\templates\appointments\index.twig"
)

foreach ($file in $files) {
    if (Test-Path $file) {
        $content = Get-Content $file -Raw
        
        # Increase close button touch target
        $content = $content -replace 'text-gray-400 hover:text-gray-500"', 'text-gray-400 hover:text-gray-500 p-2 -mr-2 bg-gray-50 rounded-full hover:bg-gray-100 transition-colors"'
        $content = $content -replace "text-gray-400 hover:text-gray-500'", "text-gray-400 hover:text-gray-500 p-2 -mr-2 bg-gray-50 rounded-full hover:bg-gray-100 transition-colors'"
        
        # Also ensure primary buttons in forms are slightly larger
        $content = $content -replace 'px-4 py-2', 'px-5 py-3'
        
        Set-Content $file -Value $content
        Write-Host "Updated $file"
    }
}
