import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class LoginScreen extends StatefulWidget {
  final String baseUrl;
  final VoidCallback onLoginExitoso;

  const LoginScreen({Key? key, required this.baseUrl, required this.onLoginExitoso}) : super(key: key);

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  String? _choferSeleccionado;
  final TextEditingController _pinController = TextEditingController();
  
  List<Map<String, dynamic>> _listaChoferes = [];
  bool _cargandoChoferes = true;
  bool _cargandoLogin = false;

  @override
  void initState() {
    super.initState();
    _cargarChoferes();
  }

  Future<void> _cargarChoferes() async {
    try {
      final String baseLimpia = widget.baseUrl.endsWith('/') 
          ? widget.baseUrl.substring(0, widget.baseUrl.length - 1) 
          : widget.baseUrl;

      // Carga la lista de choferes registrados en tu base de datos
      final response = await http.get(Uri.parse('$baseLimpia/obtener_choferes.php')).timeout(const Duration(seconds: 10));
      
      if (response.statusCode == 200) {
        final List<dynamic> data = json.decode(response.body);
        if (mounted) {
          setState(() {
            _listaChoferes = List<Map<String, dynamic>>.from(data);
            _cargandoChoferes = false;
          });
        }
      } else {
        if (mounted) setState(() => _cargandoChoferes = false);
      }
    } catch (e) {
      if (mounted) setState(() => _cargandoChoferes = false);
    }
  }

  Future<void> _registrarOIniciar() async {
    if (_choferSeleccionado == null || _choferSeleccionado!.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('⚠️ Por favor selecciona tu nombre de chofer'), backgroundColor: Colors.orange),
      );
      return;
    }

    final pin = _pinController.text.trim();
    if (pin.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('⚠️ Ingresa tu PIN numérico'), backgroundColor: Colors.orange),
      );
      return;
    }

    setState(() => _cargandoLogin = true);

    try {
      final String baseLimpia = widget.baseUrl.endsWith('/') 
          ? widget.baseUrl.substring(0, widget.baseUrl.length - 1) 
          : widget.baseUrl;

      // Envía el nombre seleccionado y el PIN al script de registro/actualización
      final response = await http.post(
        Uri.parse('$baseLimpia/api/registrar_chofer_app.php'),
        body: {'nombre': _choferSeleccionado, 'pin': pin},
      ).timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['success'] == true) {
          final choferData = data['chofer'];

          // Guardar sesión localmente para modo offline
          final prefs = await SharedPreferences.getInstance();
          await prefs.setInt('chofer_id', choferData['id']);
          await prefs.setString('chofer_usuario', choferData['usuario']);
          await prefs.setString('chofer_nombre', choferData['nombre_completo']);
          await prefs.setBool('esta_logueado', true);

          if (!mounted) return;
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('✅ ¡Bienvenido, ${choferData['nombre_completo']}!'), backgroundColor: Colors.green),
          );

          widget.onLoginExitoso();
        } else {
          if (!mounted) return;
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('❌ ${data['mensaje']}'), backgroundColor: Colors.red),
          );
        }
      } else {
        throw 'Error de servidor: ${response.statusCode}';
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('❌ Error de conexión con el servidor'), backgroundColor: Colors.red),
      );
    } finally {
      if (mounted) setState(() => _cargandoLogin = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.white,
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24.0),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 400),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Icon(Icons.local_shipping, size: 80, color: Color(0xFF4A148C)),
                const SizedBox(height: 16),
                const Text(
                  'ACAREZ DRIVER',
                  style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold, color: Color(0xFF4A148C)),
                ),
                const Text(
                  'Selecciona tu nombre e ingresa tu PIN',
                  style: TextStyle(fontSize: 14, color: Colors.grey),
                ),
                const SizedBox(height: 32),
                
                // Menú desplegable de choferes cargado desde la base de datos
                _cargandoChoferes
                    ? const CircularProgressIndicator(color: Color(0xFF4A148C))
                    : Container(
                        decoration: BoxDecoration(
                          border: Border.all(color: Colors.grey.shade400),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: DropdownButtonHideUnderline(
                          child: DropdownButtonFormField<String>(
                            value: _choferSeleccionado,
                            isExpanded: true,
                            hint: const Padding(
                              padding: EdgeInsets.symmetric(horizontal: 12),
                              child: Text("Selecciona tu nombre"),
                            ),
                            decoration: const InputDecoration(
                              border: InputBorder.none,
                              prefixIcon: Icon(Icons.person, color: Color(0xFF4A148C)),
                              contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                            ),
                            items: _listaChoferes.map<DropdownMenuItem<String>>((chofer) {
                              final nombre = chofer['nombre_chofer'].toString();
                              return DropdownMenuItem<String>(
                                value: nombre,
                                child: Text(nombre),
                              );
                            }).toList(),
                            onChanged: (value) {
                              setState(() {
                                _choferSeleccionado = value;
                              });
                            },
                          ),
                        ),
                      ),
                
                const SizedBox(height: 16),
                TextField(
                  controller: _pinController,
                  keyboardType: TextInputType.number,
                  obscureText: true,
                  maxLength: 6,
                  decoration: const InputDecoration(
                    labelText: 'PIN Numérico',
                    border: OutlineInputBorder(),
                    prefixIcon: Icon(Icons.lock, color: Color(0xFF4A148C)),
                    counterText: '',
                  ),
                ),
                const SizedBox(height: 24),
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton(
                    onPressed: _cargandoLogin ? null : _registrarOIniciar,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF4A148C),
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 16),
                    ),
                    child: _cargandoLogin
                        ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                        : const Text('ENTRAR', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
