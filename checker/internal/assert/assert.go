// Package assert evaluates API monitor assertions the way MonitorApiAssertion does.
package assert

import (
	"encoding/json"
	"fmt"
	"regexp"
	"strconv"
	"strings"
)

// Assertion is one active or inactive stored rule.
type Assertion struct {
	Path         string
	Type         string
	ExpectedType string
	Operator     string
	Expected     string
	Pattern      string
	Active       bool
}

// Result is the persisted shape of one failed or passed check.
type Result struct {
	Path        string `json:"path"`
	Type        string `json:"type,omitempty"`
	Passed      bool   `json:"passed"`
	Message     string `json:"message"`
	Actual      any    `json:"actual,omitempty"`
	Expected    any    `json:"expected,omitempty"`
	HasActual   bool   `json:"-"`
	HasExpected bool   `json:"-"`
}

// Evaluate runs stored assertions, or a single data_path existence check when none are stored.
func Evaluate(body any, rules []Assertion, dataPath string) []Result {
	if len(rules) > 0 {
		var out []Result
		for _, rule := range rules {
			if !rule.Active {
				continue
			}
			out = append(out, eval(rule, body))
		}
		return out
	}
	if dataPath == "" {
		return nil
	}
	_, exists := lookup(body, dataPath)
	message := "Value does not exist at path"
	actual := "missing"
	if exists {
		message = "Value exists at path"
		actual = "exists"
	}
	return []Result{{
		Path: dataPath, Passed: exists, Message: message,
		Actual: actual, Expected: "exists", HasActual: true, HasExpected: true,
	}}
}

func eval(rule Assertion, body any) Result {
	value, exists := lookup(body, rule.Path)
	base := Result{Path: rule.Path, Type: rule.Type, HasActual: true, HasExpected: true}
	switch rule.Type {
	case "type_check":
		actual := valueType(value)
		base.Passed = actual == rule.ExpectedType
		base.Actual = actual
		base.Expected = rule.ExpectedType
		if base.Passed {
			base.Message = "Value is of type " + rule.ExpectedType
		} else {
			base.Message = fmt.Sprintf("Expected type %s, got %s", rule.ExpectedType, actual)
		}
	case "value_compare":
		return compare(rule, value, base)
	case "exists":
		base.Passed = exists
		base.Actual = existsLabel(exists)
		base.Expected = "exists"
		if exists {
			base.Message = "Value exists at path"
		} else {
			base.Message = "Value does not exist at path"
		}
	case "not_exists":
		base.Passed = !exists
		base.Actual = existsLabel(exists)
		base.Expected = "missing"
		if base.Passed {
			base.Message = "Value does not exist at path"
		} else {
			base.Message = "Value exists at path but should not"
		}
	case "array_length":
		length, ok := lengthOf(value)
		if !ok {
			base.Message = "Value is not an array"
			base.Actual = valueType(value)
			base.Expected = fmt.Sprintf("array (length %s %s)", rule.Operator, rule.Expected)
			return base
		}
		return compare(rule, length, base)
	case "regex_match":
		pattern, ok := compilePHP(rule.Pattern)
		if !ok {
			base.Message = "Regex pattern is invalid"
			base.Actual = rule.Pattern
			base.Expected = "valid regex pattern"
			return base
		}
		text, isString := value.(string)
		if !isString {
			base.Message = "Value is not a string"
			base.Actual = valueType(value)
			base.Expected = "string matching " + rule.Pattern
			return base
		}
		base.Passed = pattern.MatchString(text)
		base.Actual = text
		base.Expected = rule.Pattern
		if base.Passed {
			base.Message = "Value matches pattern"
		} else {
			base.Message = "Value does not match pattern"
		}
	default:
		base.Passed = false
		base.Message = "Unsupported assertion type"
		base.Actual = rule.Type
		base.Expected = "supported assertion"
	}
	return base
}

func compare(rule Assertion, value any, base Result) Result {
	expected := castExpected(value, rule.Expected)
	base.Actual = value
	base.Expected = strings.TrimSpace(rule.Operator + " " + rule.Expected)
	switch rule.Operator {
	case "=":
		base.Passed = looseEqual(value, expected)
	case "!=":
		base.Passed = !looseEqual(value, expected)
	case ">":
		base.Passed = looseLess(expected, value)
	case "<":
		base.Passed = looseLess(value, expected)
	case ">=":
		base.Passed = looseEqual(value, expected) || looseLess(expected, value)
	case "<=":
		base.Passed = looseEqual(value, expected) || looseLess(value, expected)
	case "contains":
		switch typed := value.(type) {
		case string:
			base.Passed = strings.Contains(typed, rule.Expected)
		case []any:
			for _, item := range typed {
				if looseEqual(item, expected) || looseEqual(item, rule.Expected) {
					base.Passed = true
					break
				}
			}
		}
	}
	if base.Passed {
		base.Message = "Value comparison passed"
	} else {
		base.Message = fmt.Sprintf("Value comparison failed: expected %s %s", rule.Operator, rule.Expected)
	}
	return base
}

func castExpected(actual any, expected string) any {
	switch actual.(type) {
	case bool:
		return expected == "1" || strings.EqualFold(expected, "true")
	case int:
		n, _ := strconv.Atoi(strings.TrimSpace(expected))
		return n
	case float64:
		n, _ := strconv.ParseFloat(strings.TrimSpace(expected), 64)
		return n
	default:
		return expected
	}
}

func looseEqual(left, right any) bool {
	if lf, lok := asFloat(left); lok {
		if rf, rok := asFloat(right); rok {
			return lf == rf
		}
	}
	return fmt.Sprint(left) == fmt.Sprint(right)
}

func looseLess(left, right any) bool {
	lf, lok := asFloat(left)
	rf, rok := asFloat(right)
	if lok && rok {
		return lf < rf
	}
	return fmt.Sprint(left) < fmt.Sprint(right)
}

func asFloat(value any) (float64, bool) {
	switch typed := value.(type) {
	case int:
		return float64(typed), true
	case float64:
		return typed, true
	case json.Number:
		n, err := typed.Float64()
		return n, err == nil
	case string:
		n, err := strconv.ParseFloat(strings.TrimSpace(typed), 64)
		return n, err == nil
	default:
		return 0, false
	}
}

func lengthOf(value any) (int, bool) {
	switch typed := value.(type) {
	case []any:
		return len(typed), true
	case map[string]any:
		return len(typed), true
	default:
		return 0, false
	}
}

func existsLabel(exists bool) string {
	if exists {
		return "exists"
	}
	return "missing"
}

func valueType(value any) string {
	if value == nil {
		return "null"
	}
	switch typed := value.(type) {
	case bool:
		return "boolean"
	case int:
		return "integer"
	case float64:
		if typed == float64(int64(typed)) {
			return "integer"
		}
		return "float"
	case json.Number:
		if !strings.ContainsAny(typed.String(), ".eE") {
			return "integer"
		}
		return "float"
	case string:
		return "string"
	case []any, map[string]any:
		return "array"
	default:
		return "unknown"
	}
}

func lookup(body any, path string) (any, bool) {
	if path == "" {
		return body, true
	}
	current := body
	for _, part := range strings.Split(path, ".") {
		switch node := current.(type) {
		case map[string]any:
			value, ok := node[part]
			if !ok {
				return nil, false
			}
			current = value
		case []any:
			index, err := strconv.Atoi(part)
			if err != nil || index < 0 || index >= len(node) {
				return nil, false
			}
			current = node[index]
		default:
			return nil, false
		}
	}
	return current, true
}

// DecodeJSON parses a body the way PHP json_decode($body, true) does for assertion input.
// Objects stay maps, which PHP also treats as arrays. Whole numbers become int.
func DecodeJSON(body []byte) (any, bool) {
	dec := json.NewDecoder(bytesReader(body))
	dec.UseNumber()
	var value any
	if err := dec.Decode(&value); err != nil {
		return nil, false
	}
	return normalize(value), true
}

func normalize(value any) any {
	switch typed := value.(type) {
	case json.Number:
		if !strings.ContainsAny(typed.String(), ".eE") {
			if n, err := typed.Int64(); err == nil {
				return int(n)
			}
		}
		if n, err := typed.Float64(); err == nil {
			return n
		}
		return typed.String()
	case map[string]any:
		for key, item := range typed {
			typed[key] = normalize(item)
		}
		return typed
	case []any:
		for i, item := range typed {
			typed[i] = normalize(item)
		}
		return typed
	default:
		return value
	}
}

func bytesReader(body []byte) *strings.Reader {
	return strings.NewReader(string(body))
}

func compilePHP(pattern string) (*regexp.Regexp, bool) {
	if pattern == "" {
		return nil, false
	}
	expr := pattern
	flags := ""
	if len(pattern) > 2 {
		delim := pattern[0]
		if strings.ContainsRune("/%#~@;", rune(delim)) {
			end := strings.LastIndexByte(pattern, delim)
			if end > 0 {
				expr = pattern[1:end]
				flags = pattern[end+1:]
			}
		}
	}
	prefix := ""
	if strings.Contains(flags, "i") {
		prefix += "(?i)"
	}
	if strings.Contains(flags, "s") {
		prefix += "(?s)"
	}
	if strings.Contains(flags, "m") {
		prefix += "(?m)"
	}
	compiled, err := regexp.Compile(prefix + expr)
	if err != nil {
		return nil, false
	}
	return compiled, true
}
