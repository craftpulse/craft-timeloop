# Timeloop plugin for Craft CMS 3.x

This plugin created repeating dates without complex inputs

![timeloop-banner-light (1)](https://user-images.githubusercontent.com/20947573/117322933-bcbca200-ae8e-11eb-834f-1a2aeba472b6.png)

## Requirements

This plugin requires Craft CMS 3.0.0-beta.23 or later.

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

### Twig
We have two twig variables you can use to fetch out the dates.

#### getUpcoming
Use getUpcoming if you want to fetch the next date in line. 

Props:
* data: pass the field through the function
```twig
{{ craft.timeloop.getUpcoming(entry.timeloop) ? craft.timeloop.getUpcoming(entry.timeloop)|date('d/m/Y') : "There's no upciming date" }}
```

#### getReminder
Use getReminder if you want to fetch the next reminder date in line. 

Props:
* data: pass the field through the function
```twig
{{ craft.timeloop.getReminder(entry.timeloop) ? craft.timeloop.getReminder(entry.timeloop)|date('d/m/Y') : "There's no upciming reminder date" }}
```

#### getDates
Use getUpcoming if you want to fetch the next date in line. Pass the field to the variable.

Props:
* data[array]: pass the field through the function
* limit[integer]: add a limit of dates you want to return. Default set to 100
* futureDates[bool]: if you want the dates starting from today and only show future dates or start from the loopStart. Default set to true (only dates in the future)
```twig
{% set dates = craft.timeloop.getDates(entry.timeloop) %}
```

### GraphQL
If you want to use the plugin throughout GraphQL, we've added a type to provide the data to use headless

You can get the types from the data directly for `loopStart`, `loopEnd`, `loopPeriod`. To get the dates, use `dates`.

Dates arguments:
* limit[integer]: add a limit of dates you want to return. Default set to 100
* futureDates[bool]: if you want the dates starting from today and only show future dates or start from the loopStart. Default set to true (only dates in the future)
```graphql
query{
  entries(section: "homepage"){
    id,
    ...on homepage_homepage_Entry{
      dateCreated,
      title,
      timeloop {
        loopStart,
        loopEnd,
        loopPeriod,
        reminder @formatDateTime(format: "d-m-Y g:ia", timezone: "Europe/London"),
        dates(limit: 5) @formatDateTime(format: "d/m/Y" )
      }
    }
  }
}

```

## Timeloop Roadmap

Some things to do, and ideas for potential features:

* Adding timeslots for the recurring date
* Adding a custom period loop where you can set custom recurring dates
* Providing a controller to fetch if today is the first upcoming date
* Providing a controller to fetch if today is the first reminder upcoming date


Brought to you by [Percipio.London](https://percipio.london)
