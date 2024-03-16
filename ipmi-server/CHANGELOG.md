## 1.2.7
- extract all sensors that are returned by `sdr list full` command because
the `sensor` command does not work with all servers.

## 1.2.0

- extract all sensors available on the server, including power and current sensors
- allow users to provide extra params in case they need them 
(for example one can provide "-C 17" to correctly connect to the ipmi server)
- ability to chose what interface type to use when connecting to the server
  (if not provided then it will try to auto select it)
- anonymize passwords in error messages

## 1.0.0

- Initial release
