
def check_balance(filename):
    with open(filename, 'r') as f:
        content = f.read()
    
    stack = []
    pairs = {')': '(', '}': '{', ']': '['}
    
    lines = content.split('\n')
    for line_num, line in enumerate(lines, 1):
        for char_num, char in enumerate(line, 1):
            if char in '({[':
                stack.append((char, line_num, char_num))
            elif char in ')}]':
                if not stack:
                    print(f"Extra closing {char} at line {line_num}, char {char_num}")
                    return
                top_char, top_line, top_char_num = stack.pop()
                if top_char != pairs[char]:
                    print(f"Mismatch: {char} at line {line_num} does not match {top_char} from line {top_line}")
                    return
    
    if stack:
        for char, line, col in stack:
            print(f"Unclosed {char} at line {line}, char {col}")

if __name__ == "__main__":
    check_balance('/var/www/hc.mohjss.so/app/Http/Controllers/InventoryController.php')
