# Per-stack-member CPU / Memory / Temperature

The shipped `Extreme EXOS by SNMP` template gives chassis-level CPU
(`system.cpu.util[extremeCpuMonitorTotalUtilization.0]`) and chassis-level
temperature (`sensor.temp.value[extremeCurrentTemperature.0]`). On a stacked
switch you actually want one CPU and one temp reading per member. Memory is
already per-member because the template walks `extremeMemoryMonitorSystemTable`.

## What needs to be added to the template

### 1. New `CPU Discovery` LLD rule

Walks `extremeCpuMonitorSystemTable` (1.3.6.1.4.1.1916.1.32.1.4) — one row per
slot. Item prototypes:

| Item | OID | Key |
|---|---|---|
| 1-min utilization | `1.3.6.1.4.1.1916.1.32.1.4.1.8.{#SNMPINDEX}` | `system.cpu.util[extremeCpuMonitorSystemUtilization1min.{#SNMPINDEX}]` |
| 5-min utilization | `1.3.6.1.4.1.1916.1.32.1.4.1.9.{#SNMPINDEX}` | `system.cpu.util[extremeCpuMonitorSystemUtilization5min.{#SNMPINDEX}]` |

Both come back as `DisplayString` (e.g. `"22"`, `"  18"`); preprocess with
`TRIM` → numeric to coerce to float.

### 2. New temperature prototype on existing `stack.discovery`

The stack LLD already walks `extremeStackMemberTable`. Add one more prototype:

| Item | OID | Key |
|---|---|---|
| Member temperature | `1.3.6.1.4.1.1916.1.33.2.1.21.{#SNMPINDEX}` | `sensor.temp.value[extremeStackMemberCurrentTemperature.{#SNMPINDEX}]` |

Source: `extremeStackMemberCurrentTemperature` (INTEGER 0..110 °C). Trigger
prototype on `>{$TEMP_WARN}` / `>{$TEMP_CRIT}` — same threshold macros the
chassis temp item already uses.

## How the dashboard consumes it

`SwitchClient::extractStackMembers` (PHP) now scans the unified item list for
the three keys above and merges per-member `cpu1m` / `cpu5m` / `mem` / `temp`
into each row. The values fall through to the Switches → Stack Health tab via
`window.STACK_MEMBERS`. If the template hasn't been patched yet, the keys are
absent and the tab falls back to its existing demo data.

## OID reference

From `EXTREME-SOFTWARE-MONITOR-MIB` (extremeAgent.32):

```
extremeCpuMonitorSystemTable        ::= { extremeSwMonitor 1 4 }    -- 1.3.6.1.4.1.1916.1.32.1.4
  extremeCpuMonitorSystemEntry     INDEX { extremeCpuMonitorSystemSlotId }
    extremeCpuMonitorSystemSlotId             .1.1   Unsigned32
    extremeCpuMonitorSystemUtilization5secs   .1.5   DisplayString
    extremeCpuMonitorSystemUtilization10secs  .1.6   DisplayString
    extremeCpuMonitorSystemUtilization30secs  .1.7   DisplayString
    extremeCpuMonitorSystemUtilization1min    .1.8   DisplayString
    extremeCpuMonitorSystemUtilization5mins   .1.9   DisplayString
    extremeCpuMonitorSystemUtilization30mins  .1.10  DisplayString
    extremeCpuMonitorSystemUtilization1hour   .1.11  DisplayString
    extremeCpuMonitorSystemMaxUtilization     .1.12  DisplayString
```

From `EXTREME-STACKING-MIB` (extremeAgent.33):

```
extremeStackMemberTable             ::= { extremeStackable 2 }      -- 1.3.6.1.4.1.1916.1.33.2
  extremeStackMemberEntry          INDEX { extremeStackMemberSlotId }
    extremeStackMemberSlotId                  .1.1   INTEGER(1..8)
    extremeStackMemberOperStatus              .1.3   INTEGER {up(1),down(2),mismatch(3)}
    extremeStackMemberRole                    .1.4   INTEGER {master(1),slave(2),backup(3)}
    extremeStackMemberCurImageVersion         .1.7   DisplayString  -- could surface as "EXOS" pill per member
    extremeStackMemberCurrentTemperature      .1.21  INTEGER(0..110)
```

The existing `Memory Discovery` LLD (key `memory.discovery`) already walks
`extremeMemoryMonitorSystemTable` (1.3.6.1.4.1.1916.1.32.2.2) and produces the
calculated key `vm.memory.util[<slot>]`, so per-member memory is already wired.

## Importing the patch

`per-member-health.yaml` in this folder is a Zabbix YAML fragment shaped like
an export — paste its `discovery_rules` items under the `Extreme EXOS by SNMP`
template's existing `discovery_rules:` block, or import the whole file (it
declares the template by name and Zabbix will merge). UUIDs are stable so a
re-import won't duplicate items.
