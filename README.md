# Timeloop plugin for Craft CMS 3.x

This plugin creates repeating dates without complex inputs

![timeloop-banner-light](https://user-images.githubusercontent.com/20947573/117322933-bcbca200-ae8e-11eb-834f-1a2aeba472b6.png)

## Requirements

This plugin requires Craft CMS 3.3.0 or later.

## Installation

To install the plugin, follow these instructions:

1. Open your terminal and go to your Craft project:

```twig
    cd/path/to/project
```

2. Tell Composer to load the plugin:

```twig
    composer require percipiolondon/craft-timeloop
```

3. In the Control Panel, go to Settings → Plugins and click the “Install” button.

## Timeloop Overview

The Timeloop plugin provides recurring dates based on a starting date and a regular loop period.

**Example**: Set a payment date for employees on the first of each month.
**Example**: Display the upcoming dance class from a dance school that repeats every Tuesday and Thursday.
**Example**: Display the next school board meeting, which takes place every third Friday of the month.

## Configuring the Timeloop field.

The following configuration options are available for the field:

- **Show Times**: When selected, this will allow a start time and end time for the recurring dates to be chosen. 

## Using Timeloop

### Timeloop field

Once timeloop is installed and the field attached to an entry type you'll be able to begin setting up recurring dates for your entries. Below are two examples: 

1. Using timeloop to create a weekly event that repeats every Monday and Friday of each week.
<img width="1310" alt="timeloop-week-screen" src="https://user-images.githubusercontent.com/1466061/142441246-50b8128b-8aaf-400d-b5b8-3682a9349fee.png">

2. Using Timeloop to create an monthly event that repeats on the last Sunday of every month. 
<img width="1308" alt="timeloop-month-screen" src="https://user-images.githubusercontent.com/1466061/142441251-b96fdfd0-261a-4a0e-b8ae-2c0d898969f9.png">

Once configured, you'll be able to start fetching start, upcoming and end dates for your Timeloop.  

### The Timeloop Model

#### Fetching dates (returned as DateTime objects)

Get the start date from the loop (this includes the time set in `loopStartTime`):

```twig
    {{ entry.timeloop.loopStartDate | date('Y-m-d\\TH:i:sP') }}
```

Get the end date from the loop (this includes the time set in `loopEndTime`):

```twig
    {{ entry.timeloop.loopEndDate | date('Y-m-d\\TH:i:sP') }}
```

Get the start time from the loop:

```twig
    {{ entry.timeloop.loopStartTime | date('H:i:s') }}
```

Get the end time from the loop:

```twig
    {{ entry.timeloop.loopEndTime | date('H:i:s') }}
```

Get an 'array' of dates between the chosen start and end date as (DateTime objects):

```twig
    {% for date in entry.timeloop.dates %}
        {{ date | date('Y-m-d\\TH:i:sP') }}
    {% endfor %}
```

This generated set of dates takes all the field values into consideration (frequency, cycle and custom settings)

#### Upcoming Dates (returned as DateTime Objects)

Get the first upcoming date. If there is no `upcoming` date the returned value will be `false`:

```twig
    {{ entry.timeloop.upcoming ? entry.timeloop.upcoming | date('Y-m-d\\TH:i:sP') : 'no upcoming date' }}
```

Get the date that is following the first upcoming date. If there is no `nextUpcoming` date the returned value will be `false`:

```twig
    {{ entry.timeloop.nextUpcoming ? entry.timeloop.nextUpcoming | date('Y-m-d\\TH:i:sP') : 'no next upcoming date' }}
```

### Period Model

Get the frequency of the period (e.g. P2D, P1W, P4M, ...) as (DateTimePeriod String):

```twig
    {{ entry.timeloop.period.frequency }}
```

Get the set cycle for the frequency (e.g. 1, 4, 8) as (Integer):

```twig
    {{ entry.timeloop.period.cycle }}
```

Display the selected days if the frequency is set to weekly as (Array):

```twig
    {% for day in entry.timeloop.period.days %}
        {{ day }}
    {% endfor %}
```

The above will parse the names of the selected days when weekly has been chosen as frequency.

### Timestring Model

Get the ordinal of a monthly set loop (e.g. first, second, ..., last)

**warning:** If the chosen frequency is not monthly, the returned value will be `null`.
**warning:** If the frequency is set to monthly and no timestring selection has been made, the returned value will be `none` as (String).

```twig
    {{ entry.timeloop.timestring.ordinal ?? 'not set' }}
```

### Reminder Model

Get a reminder before the recurring date occurs (DateTime)

```twig
    {{ entry.timeloop.reminder | date('Y-m-d\\TH:i:sP') }}
```

### GraphQL

If you want to use the plugin through GraphQL, we've added a custom GraphQL Type to provide the field data.

You can get the DateTime Types from the data directly for:
* `loopStartDate` will return the start date.
* `loopStartTime` will return the start time, defaults to `00:00:00` when no start time has been entered or `showTimes` is set to false.
* `loopEndDate` will return the end date.
* `loopEndTime` will return the end time, defaults to `23:59:59` when no end time has been entered or `showTimes` is set to false.
* `loopReminder` will return the reminder for the first upcoming date, if any.

#### Loop Period

You can get the `loopPeriod` object in the query as follows:

```graphql
    loopPeriod {
        frequency
        cycle
        days
        timestring {
          ordinal
          day
        }
    }
```

* `frequency` will return the chosen frequency (P1D / P1W / P1M / P1Y).
* `cycle` will return the entered cycle value.
* `days` will return an array that contains the selected days of the week as string.
* `timestring` will return an object that contains the `ordinal` (e.g. last) and `day` (e.g. Saturday) values.

#### The Dates

To get an array of formatted dates, use `dates`.

##### Dates arguments:

* `limit` (Integer): add a limit of dates you want to return, defaults to `100`.
* `futureDates` (Boolean): if you want to show future dates only, defaults to `true`.

##### Dates directives:

`formatDateTime(timezone: "Europe/London" format: "d/m/Y")`

```graphql
query{
  entries(section: "section"){
    id,
    ...on section_section_Entry {
      dateCreated,
      title,
      timeloop {
        loopReminder
        loopStartDate
        loopStartTime
        loopEndDate
        loopEndTime
        loopPeriod {
          frequency
          cycle
          days
        }
        getDates(limit: 10, futureDates: false)
        getReminder
        getUpcoming
        getNextUpcoming
      }
    }
  }
}
```

## Timeloop Roadmap

Features for the future:

* Add controllers to be used for a cronjob or other automated tasks.
* Make the field translatable.
* Provide language translations.
* Add the possibility to blacklist dates (e.g. do not repeat the dates during July/August).
* Add holiday settings (Bank holidays to take into consideration when displaying upcoming dates).
* Localise holidays based on the CraftCMS timezone settings.

And many more!

Brought to you by [Percipio.London](https://percipio.london)
