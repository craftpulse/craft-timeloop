# Timeloop plugin for Craft CMS 3.x

This plugin created repeating dates without complex inputs

![timeloop-banner-light (1)](https://user-images.githubusercontent.com/20947573/117322933-bcbca200-ae8e-11eb-834f-1a2aeba472b6.png)

## Requirements

This plugin requires Craft CMS 3.3.0 or later.

## Installation

To install the plugin, follow these instructions.

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to load the plugin:

        composer require percipiolondon/craft-timeloop

3. In the Control Panel, go to Settings → Plugins and click the “Install” button for Timeloop.

## Timeloop Overview

The Timeloop plugin provides a set of recurring dates based on a starting date and a recurring loop period.

Example: I want to set a pay date for my employees on the first of each month

## Configuring Timeloop

More configuration will be provided in the future

## Using Timeloop

### The Timeloop Model

#### Getting the entered dates ( returned as DateTime Objects )

Getting the startDate for the loop ( this includes the time set in loopStartTime )
```twig
    {{ entry.timeloop.loopStartDate | date('Y-m-d\\TH:i:sP') }}
```

Getting the endDate for the loop ( this includes the time set in loopEndHour )
```twig
    {{ entry.timeloop.loopEndDate | date('Y-m-d\\TH:i:sP') }}
```

Getting the startTime for the loop
```twig
    {{ entry.timeloop.loopStartTime | date('H:i:s') }}
```

Getting the endTime for the loop
```twig
    {{ entry.timeloop.loopEndTime | date('H:i:s') }}
```

Getting an array of dates between the selected start and end dates ( Array with DateTime Objects ).
This generated set of dates takes all the field values into consideration ( frequency, cycle, custom )
```twig
    {% for date in entry.timeloop.dates %}
        {{ date | date('Y-m-d\\TH:i:sP') }}
    {% endfor %}
```


#### Upcoming Dates ( returned as DateTime Objects )

Getting the first upcoming date
```twig
    {{ entry.timeloop.upcoming | date('Y-m-d\\TH:i:sP') }}
```

Getting the next upcoming date
```twig
    {{ entry.timeloop.nextUpcoming | date('Y-m-d\\TH:i:sP') }}
```

### Period Model

Getting the frequency ( DateTimePeriod String )
```twig
    {{ entry.timeloop.period.frequency }}
```

Getting the cycle ( Integer )
```twig
    {{ entry.timeloop.period.cycle }}
```

Getting the days ( Array ),
This will parse the names of the days selected when Daily has been chosen as frequency
```twig
    {% for day in entry.timeloop.period.days %}
        {{ day }}
    {% endfor %}
```

### Timestring Model

Get the ordinal of a monthly set loop (e.g. first, second, ..., last)

**warning:** This will return `null` if the loop is set to anything else than monthly!<br>
**warning:** This will return `none` as string if the loop is set to monthly, but no timestring selection has been made!

```twig
    {{ entry.timeloop.timestring.ordinal ?? 'not set' }}
```

### Reminder Model ( WIP - not ready for production )

### GraphQL
If you want to use the plugin throughout GraphQL, we've added a type to provide the data to use headless

You can get the DateTimeTypes from the data directly for `loopStartDate`, `loopStartTime`, `loopEndDate`, `loopEndTime`, `loopPeriod`, `loopReminder`.

You can get a simple array for the loopPeriod values with `loopPeriod` ( will be updated to a new GQL Type )

To get an array of dates in formatted dates, use `dates`.

Dates arguments:
* limit[integer]: add a limit of dates you want to return. Default set to 100
* futureDates[bool]: if you want the dates starting from today and only show future dates or start from the loopStart. Default set to true (only dates in the future)

Dates directives:
* formatDateTime(timezone: "Europe/London" format: "d/m/Y")


```graphql
query{
  entries(section: "homepage"){
    id,
    ...on homepage_homepage_Entry{
      dateCreated,
      title,
      timeloop {
        loopReminder,
        loopStartDate,
        loopStartTime,
        loopEndDate,
        loopEndTime,
        loopPeriod,
        dates(limit: 5) @formatDateTime(format: "d/m/Y" )
      }
    }
  }
}

```

## Timeloop Roadmap

Some things to do, and ideas for potential features:

* Mutations for GQL
* Reminder Support - with custom entries
* Provide additional GQL Type for LoopPeriod and TimeString Models
* Make Field Translatable
* Provide Translations
* Add the posibilities to blocklist dates ( which shouldn't be parsed )
* Add Bank Holidays and Holiday settings
* Localise Bank Holidays and Holidays based on Craft TimeZone Settings
* Providing a controller to fetch if today is the first upcoming date
* Providing a controller to fetch if today is the first reminder upcoming date
* And Many more!


Brought to you by [Percipio.London](https://percipio.london)
