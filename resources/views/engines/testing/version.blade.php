@if (version_compare($line, '9.0', '>='))
echo new
@else
echo old
@endif
