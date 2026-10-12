import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:geocoding/geocoding.dart';
import 'package:image_picker/image_picker.dart';
import 'package:http/http.dart' as http;
import 'dart:convert';
import 'dart:io';
import 'dart:ui' as ui;
import 'package:shared_preferences/shared_preferences.dart';
import 'pantallas/mis_rutas.dart';
import 'pantallas/login_screen.dart';
import 'services/local_storage_service.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // ========== INICIALIZAR SERVICIOS ==========
  await LocalStorageService().init();

  // Verificamos si ya hay una sesión guardada localmente
  final prefs = await SharedPreferences.getInstance();
  bool estaLogueado = prefs.getBool('esta_logueado') ?? false;

  runApp(AcarezApp(estaLogueado: estaLogueado));
}

// ==================== APLICACIÓN PRINCIPAL ====================
class AcarezApp extends StatelessWidget {
  final bool estaLogueado;
  const AcarezApp({super.key, required this.estaLogueado});

  @override
  Widget build(BuildContext context) {
    const String baseUrl = "https://acarez-backend.onrender.com";

    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'ACAREZ LOGISTICA',
      theme: ThemeData(
        primarySwatch: Colors.purple,
        colorScheme: ColorScheme.fromSwatch(primarySwatch: Colors.purple)
            .copyWith(
              primary: const Color(0xFF4A148C),
              secondary: const Color(0xFF6A1B9A),
            ),
        useMaterial3: false,
      ),
      // Si está logueado va directo a las rutas, si no, abre la pantalla de Login
      home: estaLogueado 
          ? const RegistroViaje(baseUrl: baseUrl)
          : LoginScreen(
              baseUrl: baseUrl,
              onLoginExitoso: () {
                // Reiniciamos la app al autenticarse con éxito
                runApp(const AcarezApp(estaLogueado: true));
              },
            ),
    );
  }
}

// ==================== WIDGET PRINCIPAL ====================
class RegistroViaje extends StatefulWidget {
  final String baseUrl;
  const RegistroViaje({super.key, required this.baseUrl});

  @override
  State<RegistroViaje> createState() => _RegistroViajeState();
}

class _RegistroViajeState extends State<RegistroViaje>
    with SingleTickerProviderStateMixin {
  final _choferCtrl = TextEditingController();
  final _placasCtrl = TextEditingController();
  final _noEconomicoCtrl = TextEditingController();

  List<String> _listaPlacas = [];
  List<String> _listaNoEconomico = [];
  List<Map<String, dynamic>> _listaChoferes = [];
  bool _catalogosCargados = false;
  bool _choferesCargados = false;
  String _ubicacionActual = "Obteniendo dirección...";

  bool _enviando = false;
  late AnimationController _logoController;
  late Animation<double> _logoRotation;
  int _facturasEntregadas = 0;
  int _facturasOptimistas = 0;
  String _nombreChoferLogueado = '';

  @override
  void initState() {
    super.initState();

    _logoController = AnimationController(
      duration: const Duration(milliseconds: 800),
      vsync: this,
    );
    _logoRotation = Tween<double>(begin: 0, end: 1).animate(
      CurvedAnimation(parent: _logoController, curve: Curves.easeInOut),
    );

    WidgetsBinding.instance.addPostFrameCallback((_) {
      _cargarDatosChoferLogueado();
      _cargarCatalogos();
      _cargarChoferes();
      _solicitarPermisosUbicacion();
    });
  }

  // Cargar el nombre del chofer desde la sesión guardada localmente (Funciona sin internet)
  Future<void> _cargarDatosChoferLogueado() async {
    final prefs = await SharedPreferences.getInstance();
    final nombreCompleto = prefs.getString('chofer_nombre') ?? '';
    final usuario = prefs.getString('chofer_usuario') ?? '';

    setState(() {
      _nombreChoferLogueado = nombreCompleto.isNotEmpty ? nombreCompleto : usuario;
      _choferCtrl.text = _nombreChoferLogueado;
    });
    
    if (_choferCtrl.text.isNotEmpty) {
      _cargarContadorFacturas();
    }
  }

  @override
  void dispose() {
    _logoController.dispose();
    _choferCtrl.dispose();
    _placasCtrl.dispose();
    _noEconomicoCtrl.dispose();
    super.dispose();
  }

  void _animarLogo() {
    _logoController.reset();
    _logoController.forward();
  }

  Future<void> _cargarCatalogos() async {
    _animarLogo();
    setState(() => _catalogosCargados = false);
    try {
      final url = Uri.parse("${widget.baseUrl}/obtener_catalogos.php");
      final response = await http.get(url).timeout(const Duration(seconds: 10));
      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        setState(() {
          _listaPlacas = List<String>.from(data['placas']);
          _listaNoEconomico = List<String>.from(data['no_economico']);
          _catalogosCargados = true;
        });
      } else {
        _usarCatalogosDefecto();
      }
    } catch (e) {
      _usarCatalogosDefecto();
    }
  }

  void _usarCatalogosDefecto() {
    setState(() {
      _listaPlacas = ['MJY388C', 'MKA605C', 'MSB505A', 'NFD381C'];
      _listaNoEconomico = ['A-01', 'A-02', 'A-03', 'A-04', 'A-05'];
      _catalogosCargados = true;
    });
  }

  Future<void> _cargarChoferes() async {
    setState(() => _choferesCargados = false);
    try {
      final url = Uri.parse("${widget.baseUrl}/obtener_choferes.php");
      final response = await http.get(url).timeout(const Duration(seconds: 10));
      if (response.statusCode == 200) {
        final List<dynamic> data = json.decode(response.body);
        setState(() {
          _listaChoferes = List<Map<String, dynamic>>.from(data);
          _choferesCargados = true;
          
          // Intentar autocompletar placas y no. económico si coincide con el chofer logueado
          if (_choferCtrl.text.isNotEmpty && _listaChoferes.isNotEmpty) {
            final selected = _listaChoferes.firstWhere(
              (c) => c['nombre_chofer'].toString().toLowerCase() == _choferCtrl.text.toLowerCase(),
              orElse: () => <String, dynamic>{},
            );
            if (selected.isNotEmpty) {
              _placasCtrl.text = selected['placas'].toString();
              _noEconomicoCtrl.text = selected['numero_economico'].toString();
            }
          }
        });
      } else {
        setState(() => _choferesCargados = true);
      }
    } catch (e) {
      debugPrint("Error cargando choferes (Modo Offline o sin red): $e");
      setState(() => _choferesCargados = true);
    }
  }

  Future<void> _cargarContadorFacturas() async {
    final chofer = _choferCtrl.text.trim();
    if (chofer.isEmpty) {
      setState(() {
        _facturasEntregadas = 0;
        _facturasOptimistas = 0;
      });
      return;
    }
    final hoy = DateTime.now();
    final fechaStr =
        "${hoy.year}-${hoy.month.toString().padLeft(2, '0')}-${hoy.day.toString().padLeft(2, '0')}";
    try {
      final url = Uri.parse(
        "${widget.baseUrl}/obtener_contador_facturas.php?chofer=${Uri.encodeComponent(chofer)}&fecha=$fechaStr",
      );
      final response = await http.get(url).timeout(const Duration(seconds: 10));
      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data.containsKey('total')) {
          setState(() {
            _facturasEntregadas = data['total'];
            _facturasOptimistas = _facturasEntregadas;
          });
        }
      }
    } catch (e) {
      debugPrint("Error al cargar contador offline: $e");
    }
  }

  Future<void> _solicitarPermisosUbicacion() async {
    LocationPermission permiso = await Geolocator.checkPermission();
    if (permiso == LocationPermission.denied) {
      permiso = await Geolocator.requestPermission();
    }
    await _obtenerUbicacion();
  }

  Future<void> _obtenerUbicacion() async {
    _animarLogo();
    try {
      final posicion = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
      );
      final marcas = await placemarkFromCoordinates(
        posicion.latitude,
        posicion.longitude,
      );
      if (marcas.isNotEmpty) {
        setState(
          () => _ubicacionActual = "${marcas[0].street}, ${marcas[0].locality}",
        );
      }
    } catch (e) {
      setState(() => _ubicacionActual = "📍 Ubicación no disponible");
    }
  }

  PreferredSizeWidget _buildAppBar() {
    return AppBar(
      backgroundColor: Colors.white,
      iconTheme: const IconThemeData(color: Color(0xFF4A148C)),
      elevation: 1,
      title: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          GestureDetector(
            onTap: _animarLogo,
            child: AnimatedBuilder(
              animation: _logoRotation,
              builder: (context, child) => Transform.rotate(
                angle: _logoRotation.value * 6.28318,
                child: child,
              ),
              child: Image.asset(
                'assets/images/acarez_2.png',
                width: 45,
                height: 45,
                errorBuilder: (c, e, s) => const Icon(
                  Icons.business,
                  size: 28,
                  color: Color(0xFF4A148C),
                ),
              ),
            ),
          ),
          const SizedBox(width: 12),
          const Text(
            "ACAREZ LOGÍSTICA",
            style: TextStyle(
              fontSize: 18,
              fontWeight: FontWeight.w500,
              color: Color(0xFF4A148C),
            ),
          ),
        ],
      ),
      actions: [
        // Botón para cerrar sesión
        IconButton(
          icon: const Icon(Icons.logout, color: Colors.red),
          tooltip: "Cerrar Sesión",
          onPressed: () async {
            final prefs = await SharedPreferences.getInstance();
            await prefs.clear(); // Limpia los datos locales del chofer
            if (!mounted) return;
            // Regresa a la pantalla de Login
            runApp(const AcarezApp(estaLogueado: false));
          },
        ),
        if (_choferCtrl.text.isNotEmpty)
          IconButton(
            icon: const Icon(Icons.alt_route, color: Color(0xFF4A148C)),
            tooltip: "Mis Rutas",
            onPressed: () {
              Navigator.push(
                context,
                MaterialPageRoute(
                  builder: (context) => MisRutasScreen(
                    chofer: _choferCtrl.text,
                    baseUrl: widget.baseUrl,
                  ),
                ),
              );
            },
          ),
        if (_enviando)
          const Padding(
            padding: EdgeInsets.all(8.0),
            child: CircularProgressIndicator(color: Color(0xFF4A148C)),
          ),
      ],
    );
  }

  Widget _buildTarjetaUbicacion() {
    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: const Color(0xFFEDE7F6),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          const Icon(Icons.location_on, color: Colors.red, size: 18),
          const SizedBox(width: 10),
          Expanded(
            child: Text(_ubicacionActual, style: const TextStyle(fontSize: 11)),
          ),
          IconButton(
            icon: const Icon(Icons.refresh, size: 18),
            onPressed: _obtenerUbicacion,
          ),
        ],
      ),
    );
  }

  Widget _buildTextField(
    TextEditingController ctrl,
    String label,
    IconData icon, {
    bool readOnly = false,
  }) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: TextField(
        controller: ctrl,
        readOnly: readOnly,
        decoration: InputDecoration(
          labelText: label,
          border: const OutlineInputBorder(),
          prefixIcon: Icon(icon),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: _buildAppBar(),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _buildTarjetaUbicacion(),
            const SizedBox(height: 16),
            // Muestra el nombre del chofer logueado de manera fija o editable bloqueada
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: TextField(
                controller: _choferCtrl,
                readOnly: true,
                decoration: const InputDecoration(
                  labelText: "Chofer Conectado",
                  border: OutlineInputBorder(),
                  prefixIcon: Icon(Icons.person),
                ),
              ),
            ),
            const SizedBox(height: 8),
            _buildTextField(_placasCtrl, "Placas", Icons.directions_car, readOnly: true),
            _buildTextField(_noEconomicoCtrl, "No. Económico", Icons.pin, readOnly: true),
            const SizedBox(height: 24),
            
            // ========== BOTÓN PRINCIPAL A MIS RUTAS ==========
            SizedBox(
              height: 52,
              child: ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF4A148C),
                  foregroundColor: Colors.white,
                  disabledBackgroundColor: Colors.grey.shade300,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                  ),
                ),
                icon: const Icon(Icons.alt_route, size: 24),
                label: const Text(
                  'VER MIS RUTAS ASIGNADAS',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                ),
                onPressed: _choferCtrl.text.isEmpty
                    ? null
                    : () {
                        Navigator.push(
                          context,
                          MaterialPageRoute(
                            builder: (context) => MisRutasScreen(
                              chofer: _choferCtrl.text,
                              baseUrl: widget.baseUrl,
                            ),
                          ),
                        );
                      },
              ),
            ),
          ],
        ),
      ),
    );
  }
}
