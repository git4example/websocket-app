# websocket-app

client.html tries to make Websocket connection with server.php application hosted.

Change following line in client.html to reflect your server DNS. 

```
var host = "ws://application.elb.meetyd.com:80/websocket/server.php";
```

## Server :

Create httpd server with server.php application server behind ALB under path based routing `/websocket/server.php`

- Default HTTP application listen for server is on 80 
- Websocket application listener for server is on 8080 

check Apache server status :  
`sudo service httpd status`

Start websocket application which will be running on port 8080 : 
`php -q websocket/server.php`