# IndexNow module for ProcessWire

ProcessWire module to send all created/updated/deleted URIs on scheduled basis to [IndexNow](https://indexnow.org). IndexNow submits the URIs to several search engines (currently [Microsoft Bing](https://www.bing.com/), [Naver](https://www.naver.com/), [Seznam](https://www.seznam.cz/), [Yandex](https://yandex.com/) and [Yep](https://yep.com/)).

Requires the following core module:
- [LazyCron](https://processwire.com/modules/lazy-cron/) >= 1.0.0

## How it works
Whenever a page is created, updated or deleted, the it's saved in a waiting list. Depending on the timing schedule of the cron, the URIs are sent to IndexNow. Whenever a page is updated during it's URI is not yet submitted to IndexNow, it is only sent once to IndexNow.

[Multilanguage sites](https://processwire.com/docs/multi-language-support/) are supported.


## Configuration settings

### IndexNow key
You need an API key to verify the ownership of your domain. You can just use *any* key or get one from [Bing's Getting Started page](https://www.bing.com/indexnow/getstarted#implementation). The key you generate there is not send to any sever or stored somewhere, so there's no need to "register" it somewhere.
Enter the API key in the module settings.

### Allowed Templates
Select the page templates of the pages which should be sent to IndexNow.

### Timing schedule of cron
Lists the various options the LazyCron module provides when the cron should be executed. The value "Never" disables the cron.

### URIs per execution
Number of URIs which are sent to IndexNow during one LazyCron execution. IndexNow accepts not more than 10.000 per batch.

### Grace period after saving
How many seconds should the module wait after saving a page before sending it to IndexNow? Prevents sending it to often to IndexNow when multiple changes occur within a short time period.

### After how many days should old entries be deleted?
The log table of IndexNow keeps track of all submitted pages. This table is cleaned up after a certain time. You can specify how many days old entries should be kept in the log.

## Caveats
The module sees which page was edited. It does not see, in which **other** pages this certain page is included. So if you e.g. create a blog entry, the module will send the blog entry to IndexNow but not the parent overview page.


[//]: <> (## Usage)