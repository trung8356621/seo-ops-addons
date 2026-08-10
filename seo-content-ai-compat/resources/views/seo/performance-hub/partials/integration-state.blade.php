@if (($state['message'] ?? '') !== '')
    <div class="performance-hub-integration-state" role="status">
        <p>{{ $state['message'] }}</p>
    </div>
@endif
