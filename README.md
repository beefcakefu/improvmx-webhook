# ImprovMX Webhook for AWS S3
Make use of ImprovMX's [webhook](https://improvmx.com/guides/how-to-use-webhooks-to-receive-emails-in-your-apps/) feature to save emails to AWS S3 or add it as a backup destination.

## Requirements
- Premium / Business ImprovMX account
- Web server and PHP, preferably PHP 7.4+ and with command line access
    - Setup instruction is shown from the command line. You may be able to perform the same tasks by other means.
    - PHP 7.4 is the earliest non-EOL version currently. If you only have access to earlier PHP versions, edit the requirement in `composer.json` before `composer update -a`.
    - I used a dedicated subdomain but it should co-exist as a PHP script in an existing, publicly accessible, website with some minor changes.
    - I used Apache and `mod_rewrite` in `.htaccess` but the same forwarding rules can be achieved in NGINX with `.conf` files and most other web servers.
- Composer
- Git
- AWS account
- S3 bucket
- IAM user with 'access key' credential type and at least write permission to the S3 bucket
- Meet AWS SDK for PHP [requirements](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/getting-started_requirements.html)

## Setting Up
Examples assume the following, replace as necessary.
- Mail forwarding domain: `example.com`
- Webhook endpoint: `https://improvmx.example.com/[mailbox]`
- Web server home: `/home/example/`
- Website root: `/home/example/public_html`
- S3 bucket name: `improvmx-webhook-bucket`

### AWS Credentials
IAM user should have at least these permissions:

    {
        "Version": "2012-10-17",
        "Statement": [
            {
                "Effect": "Allow",
                "Action": "s3:PutObject",
                "Resource": [
                    "arn:aws:s3:::improvmx-webhook-bucket/*"
                ]
            }
        ]
    }

Access key for IAM user should be saved in `/home/example/.aws/credentials` in the following format.

    [<profile name>]
    aws_access_key_id = <your access key id>
    aws_secret_access_key = <your secret key>

### Forwarding from ImprovMX
Example aliases:

    tom@example.com    FORWARDS TO    tom@gmail.com,https://improvmx.example.com/tom
    dick@example.com   FORWARDS TO    https://improvmx.example.com/dick
    *@example.com      FORWARDS TO    https://improvmx.example.com/catchall

Emails sent to `tom@example.com`, `dick@example.com` and everyone else are saved to S3 under `tom`, `dick` and `catchall` mailboxes respectively. Additionally `tom@gmail.com` receives a copy of emails sent to `tom@example.com`.

### Webhook
From the web server command line:

    cd ~
    git clone -b v1.0 https://github.com/beefcakefu/improvmx-webhook.git
    cd improvmx-webhook
    composer update -a

This clones the project into `/home/example/improvmx-webhook` and sets up its dependency, the AWS SDK for PHP.

    mv ~/public_html ~/public_html_backup
    ln -s ~/improvmx-webhook/public_html ~/public_html

This backs up the current public website and replaces it with the webhook script folder.

    cp settings.ini.php.example settings.ini.php

Makes a copy of the example settings for customization in the next section.

### Configuring Webhook
Open `settings.ini.php` and make some necessary changes.
- `anybody`: This can be set to `true`, where any request it receives will be saved to S3, or `false`, where only requests matching the mailbox list is saved.
- `mailboxes[]`: In effect when `anybody` is `false`. Restricts saving to S3 for the defined mailboxes.
- `regex`: In effect when `anybody` is `true`. Checks for sane mailbox values, default value should be good for most users. Edit if you use some other characters in your mailbox name.
- `limit`: Maximum allowed size for POST requests in MB. ImprovMX webhook limit is 50 MB. Default value of 68 MB is around the maximum size ImprovMX will send, accounting for a 1.35x size increase on base64 encoded attachments.
- `region`: Region of S3 bucket.
- `profile`: This should match the access key profile name in the `credentials` file above.
- `bucket`: The name of the bucket to save emails to.
- `timezone`: Email timestamp is used to create a folder hierachy for uploaded emails in S3. This specifies the timezone that the timestamp should be shown in.
- `logging`: Whether to log some basic information of each POST request, like the request size, peak memory usage and execution time.
- `logfile`: If `logging` is enabled, the filename to which log entries are written.

### PHP Directives
For the webhook to function correctly, especially for large emails, some PHP directives will need to be updated. This can be done in a number of ways depending on your platform. A template `php.ini` can be found in `/public_html/php.ini`. Amend as required.

- `post_max_size=68M`
- `upload_max_filesize=68M`
- `memory_limit=200M`
- `max_execution_time=120`

## Profit!
And that's it! Use the alias test button in the ImprovMX interface or send an email to the forwarding email address to verify everything is working correctly.

## Additional Customization
- `/public_html/index.html` is a barebone HTML for visitors to `https://improvmx.example.com`. You may wish to customize it with something more informative.
- The mailbox and message timestamp are used to create the uploaded email's folder hierachy. For example, if an email to `tom`, timestamped `Sep 01 2022 14:00:00` is received, it would be uploaded to `/tom/2022/09/01/14:00:00/`. If you wish to customize the upload path, see [webhook.php](https://github.com/beefcakefu/improvmx-webhook/blob/f5a8c076dd5b6b2b517e85d31c0a76dd1d6194ec/public_html/webhook.php#L60).

## Notes
- To prevent the situation where attachments overwrite themselves if an email contains multiple attachments of the same name, a unique prefix is generated for each attachment.
- `rawurlencode()` is applied to message subjects to be used in filenames to prevent unexpected outcome with special characters. To convert it back to something human-readable, use `rawurldecode()` or an [online service](https://www.urldecoder.org/).
- If there is interest in a query string-style mailbox definition, like `https://example.com/webhook.php?mailbox=[tom]`, it could be looked into.

## Known Issues
- S3 object keys (path + filename) are limited to 1024 bytes.
  I may address this in a future update by truncating longer email subjects / attachment filenames / inline image filenames and perhaps finding a better way to escape unsafe characters than `rawurlencode()`.

## Special Note on Security
This was meant to be a fun weekend project for me, and I accept that it is not 100% secure.

If one's webhook endpoint was exposed, it is possible for a bad actor to spoof the user-agent and spam one's S3 bucket with a tonne of large files. In my mind, if someone found my webhook endpoint, they probably already have my email address and could have spammed me by email anyway.

A possible solution would be to make use of the JSON POST request's `raw_url` attribute and ImprovMX's API key to download the email from ImprovMX's server to ensure it is an authentic, unaltered email as received by ImprovMX. However, I am not considering this at moment as it involves receiving an up-to-68-MB email twice and a complete code rewrite.

In future, should ImprovMX decide to publish a list of IP addresses from which they send JSON POST requests, or sign the requests with their private key, I can implement more robust verification before uploading to S3. For now, we will have to make do with verifying `REQUEST_METHOD` and `HTTP_USER_AGENT`.

## License
This project is released under the [GNU Affero General Public License, Version 3.0](https://www.gnu.org/licenses/agpl-3.0.en.html).
