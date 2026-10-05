@if (session('status'))
    <div class="alert-p alert-p--ok" role="status"><x-panel.icon name="check" :size="18"/><span>{{ session('status') }}</span></div>
@endif
@if (session('warning'))
    <div class="alert-p alert-p--warn" role="status"><x-panel.icon name="alert" :size="18"/><span>{{ session('warning') }}</span></div>
@endif
@if ($errors->any())
    <div class="alert-p alert-p--err" role="alert">
        <x-panel.icon name="alert" :size="18"/>
        <div>
            <strong>{{ __('panel.common.fixErrors') }}</strong>
            @if ($errors->count() <= 4)
                <ul style="margin:6px 0 0;padding-inline-start:18px">
                    @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
                </ul>
            @endif
        </div>
    </div>
@endif
