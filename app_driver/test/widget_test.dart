import 'package:flutter_test/flutter_test.dart';
import 'package:acarez_driver/main.dart';

void main() {
  testWidgets('Counter increments smoke test', (WidgetTester tester) async {
    // Build our app and trigger a frame.
    await tester.pumpWidget(const AcarezApp());

    // Verify that our app starts.
    expect(find.text('ACAREZ LOGÍSTICA'), findsOneWidget);
  });
}
