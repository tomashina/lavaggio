<span class="lavaggio-contact-hours">
    @if ($lavaggioHoursIcon ?? true)<x-fa-icon name="clock" inline class="lavaggio-contact-hours-icon" />@endif
    <span class="sr-only">Radno vrijeme: </span>{{ $lavaggioHours }}
</span>
