<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $t('title') }}</title>
    <link rel="stylesheet" href="{{ $stylesheet }}">
</head>
<body>
<main>
    <nav class="back"><a href="{{ $backUrl }}">{{ $t('back') }}</a></nav>
    <h1>{{ $t('title') }}</h1>
    <p class="notice">{{ $t('notice') }}</p>
    @foreach ($notices as $notice)
        <p class="notice">{{ $notice }}</p>
    @endforeach

    @if (session('passorde_ok'))
        <p class="message ok" role="status">{{ session('passorde_ok') }}</p>
    @endif
    @if (session('passorde_error'))
        <p class="message error" role="alert">{{ session('passorde_error') }}</p>
    @endif

    <dl>
        <dt>{{ $t('field.state') }}</dt>
        <dd><strong>{{ $t('state.'.$status->state->value) }}</strong></dd>
        <dt>{{ $t('field.reason') }}</dt>
        <dd>{{ $status->reason() }}</dd>
        @if ($licence)
            <dt>{{ $t('field.licence_id') }}</dt>
            <dd>{{ $licence->licenceId }}</dd>
            <dt>{{ $t('field.plan') }}</dt>
            <dd>{{ $licence->plan }}</dd>
            <dt>{{ $t('field.connectors') }}</dt>
            <dd>{{ $connectorNames }}</dd>
            <dt>{{ $t('field.company') }}</dt>
            <dd>{{ $licence->company ?? $t('value.none') }}</dd>
            <dt>{{ $t('field.issued_at') }}</dt>
            <dd>{{ $licence->issuedAt }}</dd>
            <dt>{{ $t('field.expires_at') }}</dt>
            <dd>{{ $licence->expiresAt ?? $t('value.no_expiry') }}</dd>
            <dt>{{ $t('field.version_max') }}</dt>
            <dd>{{ $licence->versionMax }}</dd>
            <dt>{{ $t('field.updates_until') }}</dt>
            <dd>{{ $licence->updatesUntil ?? $t('value.no_limit') }}</dd>
        @endif
        <dt>{{ $t('field.last_refresh') }}</dt>
        <dd>{{ $lastRefresh ?? $t('value.never') }}</dd>
    </dl>

    <form method="post" action="{{ route('passorde.licence.refresh') }}">
        @csrf
        <button type="submit">{{ $t('form.refresh') }}</button>
    </form>

    <h2>{{ $t('form.install_title') }}</h2>
    <form method="post" action="{{ route('passorde.licence.install') }}" enctype="multipart/form-data">
        @csrf
        <label for="licence">{{ $t('form.paste') }}</label>
        <textarea id="licence" name="licence" spellcheck="false"></textarea>
        <label for="licence_file">{{ $t('form.upload') }}</label>
        <input id="licence_file" type="file" name="licence_file" accept=".key,.txt,text/plain">
        <div><button type="submit">{{ $t('form.install') }}</button></div>
    </form>
</main>
</body>
</html>
