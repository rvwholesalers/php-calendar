<?php

declare(strict_types=1);

namespace benhall14\phpCalendar\Views;

use benhall14\phpCalendar\DayFormat;
use benhall14\phpCalendar\Event;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;

class Month extends View
{
    /**
     * @var array{color: string, startDate: (string|CarbonInterface)}
     * @noinspection PhpPrivateFieldCanBeLocalVariableInspection
     */
    private array $options = [
        'color' => '',
        'startDate' => '',
    ];

    protected function findEvents(CarbonInterface $start, CarbonInterface $end): array
    {
        // Extracting and comparing only the dates (Y-m-d) to avoid time-based exclusion
        $callback = fn(
            Event $event,
        ): bool => $start->greaterThanOrEqualTo((clone $event->start)->startOfDay()) && $start->lessThanOrEqualTo((clone $event->end)->endOfDay());

        return array_filter($this->calendar->getEvents(), $callback);
    }

    /**
     * Returns the calendar as a month view.
     *
     * @param array{color?: string, startDate?: (string|DateTimeInterface)} $options
     */
    public function render(array $options): string
    {
        $this->options = $this->initializeOptions($options);
        $calendar = $this->makeHiddenStyles();

        $startDate = $this->options['startDate'];
        $startDOW = $this->config->starting_day;
        $firstDate = $startDate->clone();
        $lastDate = $startDate->clone()->addDays($startDate->daysInMonth() - 1);

        // add days before the beginning of the month to fill the first week
        $padding = $startDate->getDaysFromStartOfWeek($startDOW);
        if ($padding > 0)
            $firstDate->addDays($padding * -1);

        // add days after the end of the month to fill the last week
        $endPadding = 6 - $lastDate->getDaysFromStartOfWeek($startDOW);
        if ($endPadding > 0)
            $lastDate->addDays($endPadding);

        $calendar .= sprintf('<table class="calendar  %s %s ">', $this->options['color'], $this->config->table_classes);

        $calendar .= $this->getHeader($startDate);

        $calendar .= '<tbody>';

        $calendar .= '<tr class="cal-week-' . $startDate->weekOfMonth . '">';

        $carbonPeriod = $firstDate->locale($this->config->locale)->toPeriod($lastDate);
        foreach ($carbonPeriod->toArray() as $carbon) {
            $calendar .= $this->renderDay($carbon);
        }

        $calendar .= '</tr>';

        $calendar .= '</tbody>';

        return $calendar . '</table>';
    }

    public function makeHiddenStyles(): string
    {
        $hiddenDays = $this->config->getHiddenDays();

        $style = '';
        foreach ($hiddenDays as $hiddenDay) {
            $day = strtolower($hiddenDay);
            $style .= sprintf('.cal-th-%s,.cal-day-%s{display:none!important;}', $day, $day);
        }

        return [] !== $hiddenDays ? sprintf('<style>%s</style>', $style) : '';
    }

    protected function getHeader(CarbonInterface $startDate): string
    {
        $string = '<thead>';

        $string .= '<tr class="calendar-title">';

        $colspan = 7 - count($this->config->getHiddenDays());
        $string .= '<th colspan="' . $colspan . '">';

        $string .= $this->config->title ?: ucfirst($startDate->locale($this->config->locale)->monthName) . ' ' . $startDate->year;

        $string .= '</th>';

        $string .= '</tr>';
        $string .= '<tr class="calendar-header">';

        $carbonPeriod = Carbon::now()->locale($this->config->locale)->startOfWeek($this->config->starting_day)->toPeriod(7);

        if ($this->config->day_format == DayFormat::Full)
            $carbon_day = 'dayName';
        else
            $carbon_day = 'shortDayName';

        foreach ($carbonPeriod->toArray() as $carbon) {

            $label = $carbon->$carbon_day;
            if ($this->config->day_format == DayFormat::Initials)
                $label = mb_substr($label, 0, 1);

            $string .= '<th class="cal-th cal-th-' . strtolower($carbon->englishDayOfWeek) . '">' . ucfirst($label) . '</th>';
        }

        $string .= '</tr>';

        return $string . '</thead>';
    }

    protected function renderDay(CarbonInterface $runningDay): string
    {
        $events = $this->findEvents((clone $runningDay)->startOfDay(), (clone $runningDay)->endOfDay());

        $classes = '';
        $event_summary = '';
        $today_class = $runningDay->isToday() ? ' today' : '';
        $string = '';
        foreach ($events as $event) {
            // is the current day the start of the event
            if ($event->start->isSameDay($runningDay)) {
                $classes .= $event->mask ? ' mask-start ' : '';
                $classes .= $event->classes;
                $event_summary .= ($event->summary) ? '<span class="event-summary-row ' . $event->box_classes . '">' . $event->summary . '</span>' : '';

                // is the current day in between the start and end of the event
            } elseif ($runningDay->betweenExcluded($event->start, $event->end)) {
                $classes .= $event->mask ? ' mask ' : '';

                // is the current day the start of the event
            } elseif ($runningDay->isSameDay($event->end)) {
                $classes .= $event->mask ? ' mask-end ' : '';
            }
        }

        $data_attributes = '';
        $data_attributes_array = $this->calendar->getDataAttributes($runningDay);

        if ($data_attributes_array) {
            foreach ($data_attributes_array as $key => $value) {
                $data_attributes .= ' ' . $key . '="' . htmlentities(strip_tags($value)) . '" ';
            }
        }

        $className = 'cal-day-' . trim(strtolower($runningDay->englishDayOfWeek) . ' ' . $classes . ' ' . $today_class);
        $title = htmlentities(strip_tags($event_summary));
        $isoDate = $runningDay->toDateString();
        $dom = $runningDay->day;

        if ($dom == 1)
            $dom .= ' ' . $runningDay->monthName;

        if ($this->calendar->hasDateHeaderCallback())
            $this->calendar->getDateHeaderCallback()($isoDate, $dom);

        $dayRender = <<<HTML
<td class="day cal-day $className" $data_attributes title="$title" data-date="$isoDate">
    <div class="cal-day-div">
        <div class="cal-day-box">
            $dom
        </div>
        <div class="cal-event-box">
            $event_summary
        </div>
    </div>
</td>
HTML;

        // check if this calendar-row is full and if so push to a new calendar row
        if ($runningDay->dayOfWeek === $this->config->starting_day) {
            $string .= '</tr>';
            // start a new calendar row if there are still days left in the month
            $string .= '<tr class="cal-week-' . $runningDay->weekOfMonth . '">';
        }

        return $string . $dayRender;
    }

    /**
     * @param array{color?: string, startDate?: (string|DateTimeInterface)} $options
     *
     * @return array{color: string, startDate: (string|CarbonInterface)}
     */
    public function initializeOptions(array $options): array
    {
        return [
            'color' => $options['color'] ?? '',
            'startDate' => Carbon::parse($options['startDate'] ?? null)->firstOfMonth(),
        ];
    }
}
