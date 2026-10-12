import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'dart:convert';
import '../models/ruta_model.dart';
import '../services/local_storage_service.dart';
import 'detalle_ruta.dart';

class MisRutasScreen extends StatefulWidget {
  final String chofer;
  final String baseUrl;

  const MisRutasScreen({
    super.key,
    required this.chofer,
    required this.baseUrl,
  });

  @override
  State<MisRutasScreen> createState() => _MisRutasScreenState();
}

class _MisRutasScreenState extends State<MisRutasScreen> {
  List<RutaProgramada> _rutasActivas = [];
  List<RutaProgramada> _rutasHistorial = [];
  bool _cargandoActivas = true;
  bool _cargandoHistorial = true;

  @override
  void initState() {
    super.initState();
    _inicializarPantalla();
  }

  Future<void> _inicializarPantalla() async {
    await _cargarTodo();
    await _verificarRutaActivaPersistente();
  }

  Future<void> _cargarTodo() async {
    await Future.wait([
      _cargarRutasAPI(tipo: 'activas'),
      _cargarRutasAPI(tipo: 'historial'),
    ]);
  }

  Future<void> _verificarRutaActivaPersistente() async {
    final int? rutaActivaId = await LocalStorageService().obtenerRutaActivaId();
    if (rutaActivaId != null && _rutasActivas.isNotEmpty && mounted) {
      try {
        final rutaActiva = _rutasActivas.firstWhere((r) => r.id == rutaActivaId);
        Navigator.push(
          context,
          MaterialPageRoute(
            builder: (context) => DetalleRutaScreen(ruta: rutaActiva),
          ),
        ).then((_) => _cargarTodo());
      } catch (e) {
        debugPrint('Ruta activa $rutaActivaId no se encontró en las activas: $e');
      }
    }
  }

  Future<void> _cargarRutasAPI({required String tipo}) async {
    if (tipo == 'activas') {
      setState(() => _cargandoActivas = true);
    } else {
      setState(() => _cargandoHistorial = true);
    }

    try {
      final String baseUrlLimpia = widget.baseUrl.endsWith('/api')
          ? widget.baseUrl
          : (widget.baseUrl.endsWith('/')
              ? '${widget.baseUrl}api'
              : '${widget.baseUrl}/api');

      final url = Uri.parse(
        "$baseUrlLimpia/obtener_rutas_programadas.php?chofer=${Uri.encodeComponent(widget.chofer)}&tipo=$tipo",
      );

      final response = await http.get(url).timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final Map<String, dynamic> responseData = json.decode(response.body);

        if (responseData['success'] == true && responseData['rutas'] != null) {
          final List<dynamic> rutasJson = responseData['rutas'];
          final List<RutaProgramada> listaMapeada =
              rutasJson.map((item) => RutaProgramada.fromJson(item)).toList();

          setState(() {
            if (tipo == 'activas') {
              _rutasActivas = listaMapeada;
            } else {
              _rutasHistorial = listaMapeada;
            }
          });
        }
      }
    } catch (e) {
      debugPrint('Excepción al cargar rutas ($tipo): $e');
    } finally {
      if (mounted) {
        setState(() {
          if (tipo == 'activas') {
            _cargandoActivas = false;
          } else {
            _cargandoHistorial = false;
          }
        });
      }
    }
  }

  Widget _construirListaRutas(List<RutaProgramada> rutas, bool cargando, {required bool esHistorial}) {
    if (cargando) {
      return const Center(
        child: CircularProgressIndicator(color: Color(0xFF4A148C)),
      );
    }

    if (rutas.isEmpty) {
      return RefreshIndicator(
        onRefresh: _cargarTodo,
        child: SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          child: Container(
            height: MediaQuery.of(context).size.height * 0.6,
            alignment: Alignment.center,
            padding: const EdgeInsets.all(24.0),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(
                  esHistorial ? Icons.history_toggle_off : Icons.local_shipping_outlined,
                  size: 64,
                  color: Colors.grey.shade400,
                ),
                const SizedBox(height: 16),
                Text(
                  esHistorial
                      ? 'No hay rutas completadas en el historial.'
                      : 'No hay rutas asignadas o activas para este chofer.',
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 16, color: Colors.grey),
                ),
              ],
            ),
          ),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _cargarTodo,
      child: ListView.builder(
        itemCount: rutas.length,
        itemBuilder: (context, index) {
          final ruta = rutas[index];
          final String estatusLwr = ruta.estatus.toLowerCase().trim();
          final bool esCompletada = estatusLwr == 'completada';

          return Card(
            elevation: esCompletada ? 1 : 3,
            color: esCompletada ? Colors.grey[100] : Colors.white,
            margin: const EdgeInsets.symmetric(
              horizontal: 12,
              vertical: 6,
            ),
            child: ListTile(
              leading: CircleAvatar(
                backgroundColor: esCompletada
                    ? Colors.blue.withOpacity(0.15)
                    : const Color(0xFF4A148C).withOpacity(0.15),
                child: Icon(
                  esCompletada ? Icons.task_alt : Icons.local_shipping,
                  color: esCompletada ? Colors.blue[700] : const Color(0xFF4A148C),
                ),
              ),
              title: Text(
                'Ruta ${ruta.numeroRuta.isNotEmpty ? ruta.numeroRuta : "#${ruta.id}"} - ${ruta.origen}',
                style: TextStyle(
                  fontWeight: FontWeight.bold,
                  color: esCompletada ? Colors.grey[700] : Colors.black87,
                ),
              ),
              subtitle: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const SizedBox(height: 4),
                  if (ruta.numeroRuta.isNotEmpty)
                    Text(
                      'ID Interno: #${ruta.id}',
                      style: TextStyle(color: Colors.grey[500], fontSize: 11),
                    ),
                  Text(
                    'Vehículo: ${ruta.placas} (${ruta.noEconomico})',
                    style: TextStyle(
                      color: esCompletada ? Colors.grey[600] : Colors.black54,
                      fontSize: 13,
                    ),
                  ),
                  Text(
                    'Estado: ${ruta.estatus.isNotEmpty ? '${ruta.estatus[0].toUpperCase()}${ruta.estatus.substring(1)}' : ''}',
                    style: TextStyle(
                      fontWeight: FontWeight.w500,
                      color: esCompletada ? Colors.blue[800] : Colors.green[700],
                      fontSize: 13,
                    ),
                  ),
                ],
              ),
              isThreeLine: true,
              trailing: Icon(
                Icons.arrow_forward_ios,
                size: 16,
                color: esCompletada ? Colors.grey : const Color(0xFF4A148C),
              ),
              onTap: () {
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (context) => DetalleRutaScreen(ruta: ruta),
                  ),
                ).then((_) => _cargarTodo());
              },
            ),
          );
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: Text('Mis Rutas - ${widget.chofer}'),
          backgroundColor: const Color(0xFF4A148C),
          foregroundColor: Colors.white,
          actions: [
            IconButton(
              icon: const Icon(Icons.refresh),
              onPressed: _cargarTodo,
            )
          ],
          bottom: const TabBar(
            indicatorColor: Colors.amber,
            labelColor: Colors.white,
            unselectedLabelColor: Colors.white70,
            tabs: [
              Tab(icon: Icon(Icons.directions_bus), text: 'Rutas Activas'),
              Tab(icon: Icon(Icons.history), text: 'Historial'),
            ],
          ),
        ),
        body: TabBarView(
          children: [
            _construirListaRutas(_rutasActivas, _cargandoActivas, esHistorial: false),
            _construirListaRutas(_rutasHistorial, _cargandoHistorial, esHistorial: true),
          ],
        ),
      ),
    );
  }
}
