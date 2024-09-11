**STILL IN DEVELOPMENT**

# IndexNow module for ProcessWire

ProcessWire module to send all created/updated/deleted URIs on scheduled basis to [IndexNow](https://indexnow.org). IndexNow submits the URIs to several search engines (currently [Microsoft Bing](https://www.bing.com/), [Naver](https://www.naver.com/), [Seznam](https://www.seznam.cz/), [Yandex](https://yandex.com/) and [Yep](https://yep.com/)).

Requires the following core module:
- [LazyCron](https://processwire.com/modules/lazy-cron/) >= 1.0.0

## How it works
Whenever a page is created, updated or deleted, the it's saved in a waiting list. Depending on the timing schedule of the cron, the URIs are sent to IndexNow. Whenever a page is updated during it's URI is not yet submitted to IndexNow, it is only sent once to IndexNow.

Multiple queued URLs are sent to IndexNow in one batch. See [*Submitting set of URLs* in the IndexNow documentation](https://www.indexnow.org/documentation) for details.

[Multilanguage sites](https://processwire.com/docs/multi-language-support/) are supported.

### What's the difference between IndexNow and a sitemap?
With a sitemap file, the search engines **pull** your URIs, while with IndexNow you  **push** changed pages to the search engines. If your content changes rarely, you might not need this. But if you have a lot of changes and you want to notify search engines about them, this is the way to go.

## Configuration settings

### IndexNow API key
#### Key Generation
You need an API key to verify the ownership of your domain. You can just use *any* key or get one from [Bing's Getting Started page](https://www.bing.com/indexnow/getstarted#implementation). The key you generate there is not send to any sever or stored somewhere, so there's no need to "register" it somewhere.
Enter the API key in the module settings.

#### Key file
The key file is named like the key, contains the key and is saved in the root directory of your ProcessWire installation. So. e.g. if your key is *abc123*, your need to create a file named `abc123.txt` in the root directory of your ProcessWire installation and write just `abc123` in it.
The module tries to create the file automatically, but if it fails (it **should** fail if you set all permissions correctly), you have to create it manually.

See [*Verifying ownership via the key* in the IndexNow documentation](https://www.indexnow.org/documentation) for more information.

### Allowed Templates
Select the page templates of the pages which should be sent to IndexNow.

### Timing schedule of cron
Lists the various options the LazyCron module provides when the cron should be executed. The value "Never" disables the cron.

### URIs per cron execution
Number of URIs which are sent to IndexNow during one LazyCron execution. IndexNow accepts not more than 10.000 per batch. If you send too many changes to IndexNow, you might get an error "429 Too Many Requests" from the IndexNow API and thus you are blocked for some time.

### Grace period after saving
How many seconds should the module wait after saving a page before sending it to IndexNow? Prevents sending it to often to IndexNow when multiple changes occur within a short time period.
Avoid submitting the same URL many times a day. If you send too many changes to IndexNow, you might get an error "429 Too Many Requests" from the IndexNow API and thus you are blocked for some time.

### Log cleaning period
The log table of IndexNow keeps track of all submitted pages. This table is cleaned up after a certain time. You can specify how many days old entries should be kept in the log.
Every URI is only listed once in ther table. So it is not a real log, as a new edit of a page "overwrites" the old submission log entry.

## Caveats
The module sees which page was edited. It does not see, in which **other** pages this certain page is included. So if you e.g. create a blog entry, the module will send the blog entry to IndexNow but not the parent blog overview page.


## Usage
Just add/edit/delete pages and the module will send the URIs – depending on the configuration – to IndexNow.

When submitting, IndexNow returns a HTTP status code. For a list of possible codes see [*Response format* in the IndexNow documentation](https://www.indexnow.org/documentation). The response code is written to your database and can be viewed through *Setup* > *IndexNow*.

