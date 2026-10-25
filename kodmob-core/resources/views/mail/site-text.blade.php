{{-- Düz metin sürümü: HTML kaçışı yok (metin e-postası). --}}
{!! $siteName !!}

@foreach ($lines as $line)
{!! $line !!}

@endforeach
@foreach ($facts as $label => $value)
{!! $label !!}: {!! $value !!}
@endforeach
@if ($action)

{!! $action['label'] !!}: {!! $action['url'] !!}
@endif

--
{!! $siteName !!} · {!! $siteUrl !!}
