Add-Type -AssemblyName System.Drawing
$img = [System.Drawing.Image]::FromFile("F:\PROJECT\SalonGMS\public\images\logo.jpeg")
$bmp = New-Object System.Drawing.Bitmap $img.Width, $img.Height
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$brush = New-Object System.Drawing.TextureBrush $img
$path = New-Object System.Drawing.Drawing2D.GraphicsPath
$path.AddEllipse(0, 0, $img.Width, $img.Height)
$g.FillPath($brush, $path)
$bmp.Save("F:\PROJECT\SalonGMS\public\images\favicon.png", [System.Drawing.Imaging.ImageFormat]::Png)
$g.Dispose()
$bmp.Dispose()
$img.Dispose()
