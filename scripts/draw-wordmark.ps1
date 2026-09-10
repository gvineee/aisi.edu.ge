Add-Type -AssemblyName PresentationCore
$ci=[Globalization.CultureInfo]::InvariantCulture
$bold=[System.Windows.Media.GlyphTypeface]::new([Uri](Resolve-Path 'design/public/noto-georgian-bold.ttf').Path)
$regular=[System.Windows.Media.GlyphTypeface]::new([Uri](Resolve-Path 'design/public/noto-georgian-regular.ttf').Path)
function Make-Line($face,$codes,$height,$gap,$targetX,$targetY){
 $parts=@();$x=0.0
 foreach($code in $codes){
  $g=$face.GetGlyphOutline($face.CharacterToGlyphMap[$code],100,100)
  $b=$g.Bounds;$s=$height/$b.Height
  $path=$g.ToString($ci)
  $tx=$x-$b.X*$s;$ty=-$b.Y*$s
  $parts += '<path transform="translate('+ $tx.ToString($ci)+' '+$ty.ToString($ci)+') scale('+$s.ToString($ci)+')" d="'+$path+'"/>'
  $x+=$b.Width*$s+$gap
 }
 return '<g transform="translate('+$targetX+' '+$targetY+')">'+($parts -join '')+'</g>'
}
$main=Make-Line $bold @(0x1C90,0x1C98,0x1CA1,0x1C98) 44 5 2 5
$sub=Make-Line $regular @(0x1CA1,0x1C99,0x1C9D,0x1C9A,0x1C90) 9 4 3 67
$svg='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 165 84" fill="#132b45"><title>სკოლა აისი</title>'+$main+$sub+'<path d="M107 71.5h32" stroke="#f5683c" stroke-width="2.5" stroke-linecap="round"/></svg>'
[IO.File]::WriteAllText((Join-Path (Get-Location) 'design/public/aisi-wordmark.svg'),$svg)
